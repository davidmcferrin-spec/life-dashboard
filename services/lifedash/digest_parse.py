"""Turn a changelog page into entries, or a reason it needs attention.

Several distinct top headings are a changelog. A title repeated by a second
copy of the page does not count. A docs page then uses the next heading level,
unless those headings are months with items under them. When none of those
shapes are present, a long visible page is stored as one hash. A short page
is a browser shell, not a content change.
"""

from __future__ import annotations

import hashlib
import re
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
from typing import Any
from urllib.parse import urljoin
from xml.etree import ElementTree

ENTRY_CAP = 120
VISIBLE_MIN = 80
SUMMARY_MAX = 800

_MONTHS = {
    "jan": 1, "january": 1, "feb": 2, "february": 2, "mar": 3, "march": 3,
    "apr": 4, "april": 4, "may": 5, "jun": 6, "june": 6, "jul": 7, "july": 7,
    "aug": 8, "august": 8, "sep": 9, "sept": 9, "september": 9, "oct": 10,
    "october": 10, "nov": 11, "november": 11, "dec": 12, "december": 12,
}
_MONTH_WORDS = {"january", "february", "march", "april", "may", "june", "july", "august", "september", "october", "november", "december"}
_GENERIC_WORDS = {
    "a", "all", "changelog", "changelogs", "cursor", "grok", "ide", "news",
    "note", "notes", "post", "posts", "release", "the", "update", "updates",
}
_DATE_TEXT = re.compile(
    r"\b(?:(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\s+(\d{1,2}),?\s+(\d{4})|(\d{4})-(\d{2})-(\d{2})|v(\d{4})\.(\d{2})\.(\d{2}))\b",
    re.I,
)
_DATE_ID = re.compile(
    r"(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*-(\d{1,2})-(\d{4})",
    re.I,
)


_VERSION_EXACT = re.compile(r"^[vV]?(\d{1,4}(?:\.\d{1,4}){1,3})$")
_VERSION_PAREN = re.compile(r"\(([vV]?\d{1,4}(?:\.\d{1,4}){1,3})\)")
_VERSION_ANY = re.compile(r"\b[vV]?(\d{1,4}(?:\.\d{1,4}){1,3})\b")


def content_hash(text: str) -> str:
    return hashlib.sha256(normalize_text(text).encode("utf-8")).hexdigest()


def version_of(title: str) -> dict[str, str] | None:
    """Parentheses win, otherwise the last dotted number. Sort pads so 1.10 follows 1.7."""
    text = normalize_text(title)
    if not text:
        return None
    exact = _VERSION_EXACT.fullmatch(text)
    if exact:
        return _pack_version(exact.group(1))
    paren = _VERSION_PAREN.search(text)
    if paren:
        return _pack_version(paren.group(1).lstrip("vV"))
    found = list(_VERSION_ANY.finditer(text))
    if not found:
        return None
    return _pack_version(found[-1].group(1))


def snapshot_text(entries: list[dict[str, Any]]) -> str | None:
    """Normalized entry text stored for a later redline. Empty when nothing was parsed."""
    if not entries:
        return None
    chunks: list[str] = []
    for entry in entries:
        chunks.append(normalize_text(str(entry.get("title") or "")))
        if entry.get("published_on"):
            chunks.append(str(entry["published_on"]))
        summary = normalize_text(str(entry.get("summary") or ""))
        if summary:
            chunks.append(summary)
        chunks.append("")
    text = "\n".join(chunks).strip()
    return text[:200_000] or None


def _pack_version(raw: str) -> dict[str, str] | None:
    raw = raw.lstrip("vV")
    parts: list[int] = []
    for piece in raw.split("."):
        if not piece.isdigit():
            return None
        number = int(piece)
        if number > 99999:
            return None
        parts.append(number)
    if len(parts) < 2 or len(parts) > 4:
        return None
    padded = parts + [0] * (4 - len(parts))
    return {
        "version_text": raw,
        "version_sort": ".".join(f"{part:05d}" for part in padded),
    }


def normalize_text(value: str) -> str:
    return re.sub(r"\s+", " ", value or "").strip()


def http_attention(status: int, body: str) -> str | None:
    low = (body or "").lower()
    if status == 304:
        return None
    if status == 403 and ("attention required" in low or "cloudflare" in low or "cf-browser" in low):
        return "Cloudflare blocked the fetch (HTTP 403)."
    if status == 403:
        return "The host refused the fetch (HTTP 403)."
    if status == 401:
        return "The host refused the fetch (HTTP 401)."
    if status == 404:
        return "The page was not found (HTTP 404)."
    if status == 429:
        return "The host asked us to slow down (HTTP 429)."
    if status >= 400:
        return f"The host returned HTTP {status}."
    return None


def discover_feed(html: str, page_url: str) -> str | None:
    found: list[tuple[int, str]] = []

    class Finder(HTMLParser):
        def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
            if tag != "link":
                return
            data = {key: value or "" for key, value in attrs}
            kind = data.get("type", "").lower()
            rel = data.get("rel", "").lower()
            href = data.get("href", "").strip()
            if "alternate" not in rel or not href:
                return
            if "rss" in kind:
                found.append((0, href))
            elif "atom" in kind:
                found.append((1, href))

    Finder().feed(html or "")
    if not found:
        return None
    found.sort()
    return urljoin(page_url, found[0][1])


def parse_feed(xml: str, page_url: str) -> tuple[str, list[dict[str, Any]]]:
    root = ElementTree.fromstring(xml)
    kind = _local(root.tag)
    mode = "atom" if kind == "feed" else "rss"
    nodes = [node for node in root.iter() if _local(node.tag) in ("item", "entry")]
    entries: list[dict[str, Any]] = []
    for node in nodes:
        title = normalize_text(_child_text(node, "title")) or "Untitled"
        link = _entry_link(node) or page_url
        published = _entry_date(node)
        summary = normalize_text(_strip_tags(_child_text(node, "description") or _child_text(node, "summary") or _child_text(node, "content")))
        key = normalize_text(_child_text(node, "guid") or _child_text(node, "id") or link)[:200]
        entries.append(_entry(key or _slug(title), title, published, urljoin(page_url, link), summary))
    return mode, _finish(entries)


def parse_html(html: str, page_url: str) -> list[dict[str, Any]]:
    events = _collect(html or "")
    if any(event[1] for event in events):
        events = [event for event in events if event[1]]
    headings = [event for event in events if event[0] == "h"]
    level = _entry_level(headings)
    if level is None:
        return []
    require_id = sum(1 for event in headings if event[2] == level and event[4] and not event[5]) >= 2
    entries: list[dict[str, Any]] = []
    current: dict[str, Any] | None = None
    pending_time: str | None = None
    now = datetime.now(timezone.utc)
    year = now.year
    month_value: int | None = None
    pending_month: str | None = None
    for event in events:
        if event[0] == "time" and event[2]:
            pending_time = str(event[2])
            if current is not None:
                current["times"].append(pending_time)
            continue
        if event[0] == "h":
            month = _month_heading(str(event[3]))
            if month is not None:
                if month_value is None and month > now.month:
                    year -= 1
                elif month_value is not None and month > month_value:
                    year -= 1
                month_value = month
                pending_month = f"{year:04d}-{month:02d}-01"
        if event[0] == "h" and _starts_entry(event, level, require_id):
            if current is not None:
                entries.append(_close(current, page_url))
            current = {
                "id": event[4],
                "title": event[3],
                "parts": [],
                "times": [pending_time] if pending_time else [],
                "href": event[6],
                "month": pending_month,
            }
            pending_time = None
            continue
        if current is None:
            continue
        if event[0] == "text" and event[2]:
            current["parts"].append(event[2])
        elif event[0] == "h" and event[3] and not _generic_title(str(event[3])) and _month_heading(str(event[3])) is None:
            current["parts"].append(event[3])
    if current is not None:
        entries.append(_close(current, page_url))
    return _finish(entries)


def page_text(html: str) -> str:
    events = _collect(html or "")
    if any(event[1] for event in events):
        events = [event for event in events if event[1]]
    return normalize_text(" ".join(event[2] for event in events if event[0] == "text"))


def read_page(
    status: int,
    body: str,
    content_type: str,
    page_url: str,
    previous_hash: str | None,
    fetch_feed: Any = None,
) -> dict[str, Any]:
    """Parse the page. An advertised feed is used when its fetch returns entries."""
    if status == 200 and not _is_feed(body, content_type) and fetch_feed is not None:
        feed_url = discover_feed(body, page_url)
        if feed_url and feed_url.rstrip("/") != page_url.rstrip("/"):
            fetched = fetch_feed(feed_url)
            if isinstance(fetched, dict) and not fetched.get("error") and int(fetched.get("status") or 0) == 200:
                parsed = interpret(200, str(fetched.get("body") or ""), str(fetched.get("content_type") or ""), feed_url, None)
                if parsed["entries"]:
                    return parsed
    return interpret(status, body, content_type, page_url, previous_hash)


def interpret(status: int, body: str, content_type: str, page_url: str, previous_hash: str | None) -> dict[str, Any]:
    """Classify one response body. A discovered feed is fetched by the caller."""
    reason = http_attention(status, body)
    if reason:
        return {"status": "needs_attention", "error": reason, "parse_mode": None, "content_hash": previous_hash, "entries": []}
    if status == 304:
        return {"status": "ok", "error": None, "parse_mode": None, "content_hash": previous_hash, "entries": [], "unchanged": True}
    if _is_feed(body, content_type):
        try:
            mode, entries = parse_feed(body, page_url)
        except ElementTree.ParseError:
            return {"status": "needs_attention", "error": "The feed could not be parsed.", "parse_mode": None, "content_hash": previous_hash, "entries": []}
        if not entries:
            return {"status": "needs_attention", "error": "The feed had no entries.", "parse_mode": mode, "content_hash": previous_hash, "entries": []}
        return {"status": "ok", "error": None, "parse_mode": mode, "content_hash": None, "entries": entries}
    entries = parse_html(body, page_url)
    if entries:
        return {"status": "ok", "error": None, "parse_mode": "html", "content_hash": None, "entries": entries}
    text = page_text(body)
    if len(text) < VISIBLE_MIN:
        return {
            "status": "needs_attention",
            "error": _shell_reason(body),
            "parse_mode": None,
            "content_hash": previous_hash,
            "entries": [],
        }
    digest = content_hash(text)
    if digest == previous_hash:
        return {"status": "ok", "error": None, "parse_mode": "hash", "content_hash": digest, "entries": []}
    snippet = text[:SUMMARY_MAX]
    return {
        "status": "ok",
        "error": None,
        "parse_mode": "hash",
        "content_hash": digest,
        "entries": [_entry("page", "Page content changed", None, page_url, snippet)],
    }


def plan_changes(existing: dict[str, str], incoming: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """existing maps entry_key to content_hash. Same hash is ignored. A new hash is an edit."""
    ops: list[dict[str, Any]] = []
    seen: set[str] = set()
    for entry in incoming:
        key = str(entry.get("entry_key") or "")
        if key == "" or key in seen:
            continue
        seen.add(key)
        digest = str(entry.get("content_hash") or "")
        if key not in existing:
            ops.append({"op": "insert", "entry": entry})
        elif existing[key] != digest:
            ops.append({"op": "update", "entry": entry})
    return ops


def _shell_reason(html: str) -> str:
    low = html.lower()
    if "attention required" in low or "cf-browser" in low or "just a moment" in low:
        return "The host showed a browser check instead of the page."
    return "The page is rendered in the browser and returned no entries."


def _is_feed(body: str, content_type: str) -> bool:
    start = (body or "").lstrip()[:300].lower()
    kind = (content_type or "").lower()
    if start.startswith("<rss") or start.startswith("<feed") or "<rss" in start[:200] or "<feed" in start[:200]:
        return True
    return ("rss" in kind or "atom" in kind) and start.startswith("<?xml")


def _collect(html: str) -> list[tuple[Any, ...]]:
    events: list[tuple[Any, ...]] = []

    class Collector(HTMLParser):
        def __init__(self) -> None:
            super().__init__(convert_charrefs=True)
            self.skip = 0
            self.chrome = 0
            self.main = 0
            self.hidden = 0
            self.hidden_tags: list[str] = []
            self.heading: dict[str, Any] | None = None
            self.buf = ""
            self.time: str | None = None
            self.time_buf = ""

        def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
            data = {key: value or "" for key, value in attrs}
            if tag in ("script", "style", "noscript", "svg"):
                self.skip += 1
            if tag in ("nav", "footer", "aside"):
                self.chrome += 1
            if tag == "main":
                self.main += 1
            if (data.get("aria-hidden") or "").lower() == "true":
                self.hidden += 1
                self.hidden_tags.append(tag)
            if self.skip or self.chrome or self.hidden:
                return
            if tag in ("h1", "h2", "h3", "h4"):
                self._flush_heading()
                slot = data.get("data-slot", "")
                self.heading = {"level": int(tag[1]), "id": data.get("id", ""), "dialog": "dialog" in slot, "href": ""}
                self.buf = ""
            elif tag == "a" and self.heading is not None:
                href = data.get("href", "").strip()
                if href and not href.startswith("#") and not self.heading["href"]:
                    self.heading["href"] = href
            elif tag == "time":
                self.time = data.get("datetime", "")
                self.time_buf = ""

        def handle_endtag(self, tag: str) -> None:
            if tag in ("script", "style", "noscript", "svg") and self.skip:
                self.skip -= 1
            if tag in ("nav", "footer", "aside") and self.chrome:
                self.chrome -= 1
            if self.hidden_tags and self.hidden_tags[-1] == tag and self.hidden:
                self.hidden_tags.pop()
                self.hidden -= 1
            if tag == "main" and self.main:
                self.main -= 1
            if tag in ("h1", "h2", "h3", "h4"):
                self._flush_heading()
            elif tag == "time" and self.time is not None and not self.skip and not self.chrome and not self.hidden:
                value = self.time or normalize_text(self.time_buf)
                if value:
                    events.append(("time", self.main > 0, value))
                self.time = None
                self.time_buf = ""

        def handle_data(self, data: str) -> None:
            if self.skip or self.chrome or self.hidden:
                return
            if self.heading is not None:
                self.buf += data
                return
            if self.time is not None:
                self.time_buf += data
            text = normalize_text(data)
            if text:
                events.append(("text", self.main > 0, text))

        def _flush_heading(self) -> None:
            if self.heading is None:
                return
            title = normalize_text(self.buf)
            if title:
                events.append((
                    "h",
                    self.main > 0,
                    self.heading["level"],
                    title,
                    _usable_id(str(self.heading["id"])),
                    self.heading["dialog"],
                    str(self.heading["href"]),
                ))
            self.heading = None
            self.buf = ""

        def close(self) -> None:
            self._flush_heading()
            super().close()

    parser = Collector()
    parser.feed(html)
    parser.close()
    return events


def _usable_id(value: str) -> str:
    hid = (value or "").strip()
    if not hid or hid.startswith("radix-") or len(hid) > 180:
        return ""
    return hid


def _entry_level(headings: list[tuple[Any, ...]]) -> int | None:
    grouped: dict[int, list[tuple[Any, ...]]] = {1: [], 2: [], 3: [], 4: []}
    for event in headings:
        if event[5]:
            continue
        grouped.setdefault(int(event[2]), []).append(event)
    unique_h1 = {normalize_text(str(event[3])).lower() for event in grouped[1]}
    if len(unique_h1) >= 2:
        return 1
    titles = [str(event[3]) for event in grouped[2]]
    if len(grouped[3]) >= 2 and _mostly_months(titles):
        return 3
    id_h2 = [event for event in grouped[2] if event[4]]
    if len(id_h2) >= 2:
        return 2
    if len(grouped[2]) >= 2:
        return 2
    if len(grouped[3]) >= 2:
        return 3
    return None


def _starts_entry(event: tuple[Any, ...], level: int, require_id: bool) -> bool:
    if event[5]:
        return False
    if event[2] == 1 and level != 1 and not _generic_title(str(event[3])):
        return True
    if event[2] != level:
        return False
    if require_id and not event[4]:
        return False
    return True


def _generic_title(title: str) -> bool:
    words = set(re.findall(r"[a-z0-9]+", title.lower()))
    return not words or words <= _GENERIC_WORDS


def _month_heading(title: str) -> int | None:
    return _MONTHS.get(normalize_text(title).lower())


def _mostly_months(titles: list[str]) -> bool:
    if len(titles) < 2:
        return False
    hits = 0
    for title in titles:
        word = title.lower().split(" ", 1)[0]
        if word in _MONTH_WORDS:
            hits += 1
    return hits >= max(2, len(titles) // 2)


def _close(current: dict[str, Any], page_url: str) -> dict[str, Any]:
    title = str(current["title"])[:300]
    summary = normalize_text(" ".join(current["parts"]))[:SUMMARY_MAX]
    published = _date_from_times(current["times"]) or _date_from_id(str(current["id"])) or _date_from_text(title) or current.get("month")
    hid = str(current["id"] or "")
    href = str(current.get("href") or "")
    if href:
        url = urljoin(page_url, href)
    elif hid:
        url = page_url + "#" + hid
    else:
        url = page_url
    key = hid or _slug(title)
    return _entry(key, title, published, url, summary)


def _entry(key: str, title: str, published: str | None, url: str, summary: str) -> dict[str, Any]:
    title = normalize_text(title)[:300] or "Untitled"
    summary = normalize_text(summary)[:SUMMARY_MAX]
    published = published if _valid_date(published) else None
    digest = content_hash(title + "\n" + (published or "") + "\n" + summary)
    found = version_of(title)
    return {
        "entry_key": (key or _slug(title))[:200],
        "title": title,
        "published_on": published,
        "url": url[:1000],
        "summary": summary,
        "content_hash": digest,
        "version_text": None if found is None else found["version_text"],
        "version_sort": None if found is None else found["version_sort"],
    }


def _finish(entries: list[dict[str, Any]]) -> list[dict[str, Any]]:
    kept: list[dict[str, Any]] = []
    seen: dict[str, str] = {}
    for entry in entries:
        key = str(entry["entry_key"])
        digest = str(entry["content_hash"])
        if key in seen and seen[key] == digest:
            continue
        if key in seen:
            key = f"{key}-{len(seen) + 1}"[:200]
            entry = {**entry, "entry_key": key}
        seen[key] = digest
        kept.append(entry)
        if len(kept) >= ENTRY_CAP:
            break
    return kept


def _date_from_times(values: list[str]) -> str | None:
    for value in values:
        found = _date_from_text(value) or _iso_date(value)
        if found:
            return found
    return None


def _date_from_id(value: str) -> str | None:
    match = _DATE_ID.search(value or "")
    if not match:
        return None
    month = _MONTHS.get(match.group(1).lower()[:3])
    if month is None:
        return None
    return _ymd(int(match.group(3)), month, int(match.group(2)))


def _date_from_text(value: str) -> str | None:
    iso = _iso_date(value)
    if iso:
        return iso
    match = _DATE_TEXT.search(value or "")
    if not match:
        return None
    if match.group(1):
        month = _MONTHS.get(match.group(1).lower()[:3])
        if month is None:
            return None
        return _ymd(int(match.group(3)), month, int(match.group(2)))
    if match.group(4):
        return _ymd(int(match.group(4)), int(match.group(5)), int(match.group(6)))
    return _ymd(int(match.group(7)), int(match.group(8)), int(match.group(9)))


def _iso_date(value: str) -> str | None:
    text = (value or "").strip()
    if len(text) >= 10 and text[4] == "-" and text[7] == "-":
        return _ymd(int(text[0:4]), int(text[5:7]), int(text[8:10])) if text[0:4].isdigit() else None
    try:
        moment = parsedate_to_datetime(text)
    except (TypeError, ValueError, IndexError, OverflowError):
        return None
    if moment is None:
        return None
    return moment.date().isoformat()


def _ymd(year: int, month: int, day: int) -> str | None:
    if year < 1990 or year > 2100 or month < 1 or month > 12 or day < 1 or day > 31:
        return None
    try:
        return f"{year:04d}-{month:02d}-{day:02d}"
    except ValueError:
        return None


def _valid_date(value: str | None) -> bool:
    if not value or not re.fullmatch(r"\d{4}-\d{2}-\d{2}", value):
        return False
    year, month, day = (int(part) for part in value.split("-"))
    return _ymd(year, month, day) == value and day <= _month_days(year, month)


def _month_days(year: int, month: int) -> int:
    if month in (1, 3, 5, 7, 8, 10, 12):
        return 31
    if month in (4, 6, 9, 11):
        return 30
    leap = year % 4 == 0 and (year % 100 != 0 or year % 400 == 0)
    return 29 if leap else 28


def _slug(title: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")
    return slug[:80] or "entry"


def _local(tag: str) -> str:
    return tag.rsplit("}", 1)[-1].lower()


def _child_text(node: ElementTree.Element, name: str) -> str:
    for child in list(node):
        if _local(child.tag) == name.lower():
            return "".join(child.itertext())
    return ""


def _entry_link(node: ElementTree.Element) -> str:
    for child in list(node):
        if _local(child.tag) != "link":
            continue
        href = child.attrib.get("href", "").strip()
        rel = child.attrib.get("rel", "alternate")
        if href and rel in ("", "alternate"):
            return href
        text = normalize_text("".join(child.itertext()))
        if text:
            return text
    return ""


def _entry_date(node: ElementTree.Element) -> str | None:
    for name in ("published", "updated", "pubDate", "date"):
        text = _child_text(node, name)
        if text:
            found = _date_from_text(text) or _iso_date(text)
            if found:
                return found
    return None


def _strip_tags(value: str) -> str:
    return re.sub(r"<[^>]+>", " ", value or "")
