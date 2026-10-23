"""Hardware-sized Ollama defaults and the health verdict for that service.

The chat model stays in the 1B–7B range. Embeddings use the model the vault
and search already expect. A missing install is not a warning; a public bind,
a dead API, a failed pull, or a missing default model is.
"""

from __future__ import annotations

import re
import subprocess
from typing import Callable

EMBED_MODEL = "nomic-embed-text"
CHAT_LARGE = "qwen2.5:7b"
CHAT_MID = "llama3.2:3b"
CHAT_SMALL = "llama3.2:1b"
BIND = "127.0.0.1:11434"
_LOOPBACK = {"127.0.0.1", "localhost", "::1"}
_MODEL = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$")
Runner = Callable[..., subprocess.CompletedProcess[str]]


def model_ok(name: str) -> bool:
    return _MODEL.fullmatch(name) is not None


def choose(ram_mib: int, vram_mib: int, accelerator: str) -> dict[str, object]:
    accel = accelerator if accelerator in ("nvidia", "amd", "cpu") else "cpu"
    ram = ram_mib if ram_mib > 0 else 0
    vram = vram_mib if vram_mib > 0 else 0
    if vram >= 8192 or (accel == "cpu" and ram >= 16384):
        chat = CHAT_LARGE
    elif vram >= 4096 or ram >= 8192:
        chat = CHAT_MID
    else:
        chat = CHAT_SMALL
    return {
        "accelerator": accel,
        "ram_mib": ram,
        "vram_mib": vram,
        "chat_model": chat,
        "embed_model": EMBED_MODEL,
    }


def parse_ram_mib(meminfo: str) -> int:
    for line in meminfo.splitlines():
        if line.startswith("MemTotal:"):
            parts = line.split()
            if len(parts) >= 2 and parts[1].isdigit():
                return int(parts[1]) // 1024
    return 0


def parse_nvidia_vram_mib(text: str) -> int | None:
    found: list[int] = []
    for line in text.splitlines():
        token = line.strip().split()[0] if line.strip() else ""
        if token.isdigit():
            found.append(int(token))
    return max(found) if found else None


def parse_amd_vram_mib(text: str) -> int | None:
    found: list[int] = []
    for line in text.splitlines():
        numbers = [int(item) for item in re.findall(r"\d+", line)]
        if not numbers:
            continue
        value = numbers[-1]
        lowered = line.lower()
        if "vram" not in lowered and "memory" not in lowered:
            continue
        if value >= 1_000_000:
            found.append(value // (1024 * 1024))
        elif "mib" in lowered or "mb" in lowered:
            found.append(value)
    return max(found) if found else None


def _run(runner: Runner, args: list[str]) -> str | None:
    try:
        proc = runner(args, capture_output=True, text=True, timeout=5)
    except (OSError, subprocess.SubprocessError):
        return None
    if proc.returncode != 0:
        return None
    return proc.stdout or ""


def detect(runner: Runner | None = None, meminfo: str | None = None) -> dict[str, object]:
    run = runner or subprocess.run
    ram = parse_ram_mib(meminfo if meminfo is not None else _read_meminfo())
    nvidia = _run(run, ["nvidia-smi", "--query-gpu=memory.total", "--format=csv,noheader,nounits"])
    if nvidia is not None:
        vram = parse_nvidia_vram_mib(nvidia)
        if vram is not None:
            return choose(ram, vram, "nvidia")
    amd = _run(run, ["rocm-smi", "--showmeminfo", "vram"])
    if amd is not None:
        vram = parse_amd_vram_mib(amd)
        if vram is not None:
            return choose(ram, vram, "amd")
    return choose(ram, 0, "cpu")


def assess(
    *,
    installed: bool,
    systemd: str,
    api_ok: bool,
    hosts: list[str],
    models: list[str],
    chat: str,
    embed: str,
    pull_state: str,
    pull_detail: str = "",
) -> dict[str, str]:
    state = (systemd or "").strip().lower() or "unknown"
    pull = (pull_state or "").strip().lower()
    if any(host not in _LOOPBACK for host in hosts):
        return _row("fail", "Ollama is listening outside this machine.")
    if pull == "pulling":
        return _row("ok", "Pulling the default models.")
    if pull == "failed":
        return _row("warn", pull_detail.strip() or "The model pull failed.")
    if state == "active" and not api_ok:
        return _row("warn", "Ollama is running but the API did not answer.")
    if installed and state in ("failed", "inactive", "deactivating"):
        return _row("warn", "Ollama service is not running.")
    if api_ok:
        names = set(models)
        missing_chat = bool(chat) and chat not in names
        missing_embed = bool(embed) and embed not in names
        if missing_chat and missing_embed:
            return _row("warn", "The default chat model and the embedding model are not installed.")
        if missing_chat:
            return _row("warn", "The default chat model is not installed.")
        if missing_embed:
            return _row("warn", "The embedding model is not installed.")
        return _row("ok", "Ollama API answered.")
    if not installed:
        return _row("ok", "Ollama is not installed.")
    return _row("warn", "Ollama API did not answer.")


def _row(status: str, detail: str) -> dict[str, str]:
    return {"name": "ollama", "status": status, "detail": detail}


def _read_meminfo() -> str:
    try:
        with open("/proc/meminfo", encoding="utf-8") as handle:
            return handle.read()
    except OSError:
        return ""
