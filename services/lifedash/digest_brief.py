"""One- or two-sentence lines for digest entries.

The local chat model writes the line once. A dead model, a bad reply, or an
empty note stores the first sentences instead, and that row is not tried again
until the note itself changes.
"""

from __future__ import annotations

import json
import re
from typing import Any, Callable
from urllib.error import URLError
from urllib.request import Request, urlopen

from .digest_parse import normalize_text
from .ollama_plan import model_ok

BRIEF_BUDGET = 20
_LOOPBACK = {"127.0.0.1", "localhost", "::1"}
_LEAD = re.compile(r"^[\[\]()•*\-–—]+\s*")
_LABEL = re.compile(r"^(summary|here is|here's)\s*:\s*", re.I)


def excerpt(text: str) -> str:
    cleaned = normalize_text(re.sub(r"<[^>]+>", " ", text or ""))
    cleaned = _LEAD.sub("", cleaned)
    if not cleaned:
        return ""
    sentences = [part.strip() for part in re.findall(r".+?(?:[.!?](?=\s|$)|$)", cleaned) if part.strip()]
    if not sentences:
        return _clip(cleaned, 220)
    chosen = sentences[0]
    if len(sentences) > 1 and len(chosen) < 140:
        chosen = chosen + " " + sentences[1]
    return _clip(chosen, 320)


def _clip(text: str, limit: int) -> str:
    if len(text) <= limit:
        return text
    cut = text[:limit]
    space = cut.rfind(" ")
    if space > 40:
        cut = cut[:space]
    return cut.rstrip(".,;: ").rstrip() + "."


def prompt_for(title: str, body: str) -> str:
    return (
        "Summarize this release note in one or two plain sentences. "
        "Do not use a title, a list, or markdown.\n\n"
        f"Title: {normalize_text(title)[:300]}\n"
        f"Note: {normalize_text(body)[:1500]}"
    )


def accept(reply: str) -> str:
    text = _LABEL.sub("", normalize_text(reply).strip("\"' "))
    if len(text) < 12:
        return ""
    return excerpt(text)


def compose(title: str, body: str, ask: Callable[[str], str | None] | None) -> tuple[str, str]:
    fallback = excerpt(body)
    if ask is None or getattr(ask, "down", False):
        return fallback, "excerpt"
    try:
        reply = ask(prompt_for(title, body))
    except (OSError, TimeoutError, URLError, ValueError):
        return fallback, "excerpt"
    if getattr(ask, "down", False):
        return fallback, "excerpt"
    cleaned = accept(reply or "")
    if not cleaned:
        return fallback, "excerpt"
    return cleaned, "ollama"


def plan_briefs(rows: list[Any], ask: Callable[[str], str | None] | None, budget: int) -> tuple[list[tuple[int, str, str]], int]:
    """Rows are (id, title, body). A live model stops at budget. A dead one excerpts the rest."""
    updates: list[tuple[int, str, str]] = []
    for row in rows:
        entry_id, title, body = int(row[0]), str(row[1] or ""), str(row[2] or "")
        if ask is not None and getattr(ask, "down", False):
            updates.append((entry_id, excerpt(body), "excerpt"))
            continue
        if budget <= 0:
            break
        budget -= 1
        text, kind = compose(title, body, ask)
        updates.append((entry_id, text, kind))
    return updates, budget


class OllamaAsk:
    """One generate call. The first connection failure marks the model down for this run."""

    def __init__(self, base: str, model: str, timeout: float = 12) -> None:
        self.base = base.rstrip("/")
        self.model = model
        self.timeout = timeout
        self.down = False

    def __call__(self, prompt: str) -> str | None:
        if self.down:
            return None
        payload = json.dumps({"model": self.model, "prompt": prompt, "stream": False}).encode("utf-8")
        request = Request(
            self.base + "/api/generate",
            data=payload,
            headers={"Content-Type": "application/json", "Accept": "application/json"},
            method="POST",
        )
        try:
            with urlopen(request, timeout=self.timeout) as response:
                raw = response.read().decode("utf-8")
        except (OSError, TimeoutError, URLError, ValueError):
            self.down = True
            return None
        try:
            body = json.loads(raw or "{}")
        except json.JSONDecodeError:
            return ""
        text = body.get("response") if isinstance(body, dict) else ""
        return text if isinstance(text, str) else ""


def loopback_base(raw: str) -> str:
    text = (raw or "").strip().rstrip("/")
    if not text.startswith("http://"):
        return "http://127.0.0.1:11434"
    host = text[len("http://"):]
    if host.startswith("["):
        end = host.find("]")
        name = host[1:end] if end > 1 else ""
        rest = host[end + 1:] if end > 1 else ""
    else:
        name, _, rest = host.partition(":")
        rest = ":" + rest if rest else ""
    if name.lower() not in _LOOPBACK:
        return "http://127.0.0.1:11434"
    if rest and not re.fullmatch(r":\d{1,5}", rest):
        return "http://127.0.0.1:11434"
    return "http://" + host


def chat_target(base_url: str, chat_model: str, plan_model: str = "") -> tuple[str, str]:
    base = loopback_base(base_url)
    model = chat_model.strip() or plan_model.strip() or "llama3.2:3b"
    if not model_ok(model):
        model = "llama3.2:3b"
    return base, model
