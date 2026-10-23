import json
from pathlib import Path

import pytest

from lifedash.mcp_server import resolve
from lifedash.ollama_models import base_url, run_defaults, run_named, write_status
from lifedash.ollama_plan import (
    assess,
    choose,
    detect,
    parse_amd_vram_mib,
    parse_nvidia_vram_mib,
    parse_ram_mib,
)
from lifedash.ollama_tools import listen_public


def test_choose_follows_ram_and_vram():
    small = choose(4096, 0, "cpu")
    mid = choose(8192, 0, "cpu")
    large_ram = choose(16384, 0, "cpu")
    large_gpu = choose(8192, 8192, "nvidia")
    mid_gpu = choose(32768, 4096, "amd")
    assert small["chat_model"] == "llama3.2:1b"
    assert mid["chat_model"] == "llama3.2:3b"
    assert large_ram["chat_model"] == "qwen2.5:7b"
    assert large_gpu["chat_model"] == "qwen2.5:7b"
    assert mid_gpu["chat_model"] == "llama3.2:3b"
    assert small["embed_model"] == mid["embed_model"] == "nomic-embed-text"
    assert choose(-1, -5, "nope")["accelerator"] == "cpu"


def test_parsers_read_gpu_and_ram_text():
    assert parse_ram_mib("MemTotal:       16384000 kB\n") == 16000
    assert parse_nvidia_vram_mib("8192\n4096\n") == 8192
    assert parse_amd_vram_mib("GPU[0] : VRAM Total Memory (B): 8589934592\n") == 8192
    assert parse_amd_vram_mib("VRAM Total: 6144 MiB\n") == 6144
    assert parse_nvidia_vram_mib("N/A\n") is None


def test_detect_prefers_nvidia_then_amd_then_cpu():
    def nvidia(args, **kwargs):
        class Proc:
            returncode = 0
            stdout = "8192\n"
        if args[0] == "nvidia-smi":
            return Proc()
        raise FileNotFoundError(args[0])

    plan = detect(nvidia, "MemTotal: 8388608 kB\n")
    assert plan["accelerator"] == "nvidia"
    assert plan["chat_model"] == "qwen2.5:7b"

    def cpu(args, **kwargs):
        raise FileNotFoundError(args[0])

    quiet = detect(cpu, "MemTotal: 4194304 kB\n")
    assert quiet["accelerator"] == "cpu"
    assert quiet["chat_model"] == "llama3.2:1b"


def test_assess_warns_only_on_real_problems():
    quiet = assess(
        installed=False, systemd="inactive", api_ok=False, hosts=[], models=[],
        chat="", embed="", pull_state="",
    )
    assert quiet["status"] == "ok"
    assert quiet["detail"] == "Ollama is not installed."

    pulling = assess(
        installed=True, systemd="active", api_ok=False, hosts=[], models=[],
        chat="llama3.2:3b", embed="nomic-embed-text", pull_state="pulling",
    )
    assert pulling["status"] == "ok"
    assert pulling["detail"] == "Pulling the default models."

    down = assess(
        installed=True, systemd="active", api_ok=False, hosts=["127.0.0.1"], models=[],
        chat="", embed="", pull_state="ready",
    )
    assert down["status"] == "warn"
    assert down["detail"] == "Ollama is running but the API did not answer."

    missing = assess(
        installed=True, systemd="active", api_ok=True, hosts=["127.0.0.1"],
        models=["nomic-embed-text"], chat="qwen2.5:7b", embed="nomic-embed-text", pull_state="ready",
    )
    assert missing["detail"] == "The default chat model is not installed."

    public = assess(
        installed=True, systemd="active", api_ok=True, hosts=["0.0.0.0"], models=["qwen2.5:7b"],
        chat="qwen2.5:7b", embed="nomic-embed-text", pull_state="ready",
    )
    assert public["status"] == "fail"
    assert public["detail"] == "Ollama is listening outside this machine."
    assert listen_public("LISTEN 0 128 0.0.0.0:11434 0.0.0.0:*")
    assert not listen_public("LISTEN 0 128 127.0.0.1:11434 0.0.0.0:*")


def test_defaults_skip_models_that_are_already_installed(monkeypatch, tmp_path):
    monkeypatch.setenv("LIFEDASH_OLLAMA_DIR", str(tmp_path))
    pulled = []

    class Body:
        def __enter__(self):
            return self

        def __exit__(self, *args):
            return False

        def read(self):
            return b'{"models":[{"name":"llama3.2:3b"},{"name":"nomic-embed-text"}]}'

        def __iter__(self):
            yield b'{"status":"success"}\n'

    def opener(req, timeout=None):
        if str(req.full_url).endswith("/api/pull"):
            pulled.append(req.data)
        return Body()

    code = run_defaults(opener, choose(8192, 0, "cpu"), store=False)
    assert code == 0
    assert pulled == []
    status = json.loads((tmp_path / "status.json").read_text())
    assert status["state"] == "ready"
    assert status["chat_model"] == "llama3.2:3b"


def test_pull_records_a_failure(monkeypatch, tmp_path):
    monkeypatch.setenv("LIFEDASH_OLLAMA_DIR", str(tmp_path))

    def opener(req, timeout=None):
        raise TimeoutError("timed out")

    assert run_named("pull", "llama3.2:3b", opener) == 1
    status = json.loads((tmp_path / "status.json").read_text())
    assert status["state"] == "failed"
    assert "timed out" in status["detail"]
    assert run_named("pull", "../x", opener) == 2


def test_base_url_stays_on_loopback(monkeypatch):
    monkeypatch.setenv("LIFEDASH_OLLAMA_BASE", "http://example.com:11434")
    assert base_url() == "http://127.0.0.1:11434"
    monkeypatch.setenv("LIFEDASH_OLLAMA_BASE", "http://127.0.0.1:11435")
    assert base_url() == "http://127.0.0.1:11435"


def test_mcp_maps_ollama_tools():
    assert resolve("ollama_status", {})[1] == "/api/v1/ollama/status"
    assert resolve("ollama_list_models", {})[0] == "GET"
    method, path, body = resolve("ollama_generate", {"prompt": "Hello"})
    assert (method, path, body) == ("POST", "/api/v1/ollama/generate", {"prompt": "Hello"})
    assert resolve("ollama_pull", {"model": "qwen2.5:7b"})[1] == "/api/v1/ollama/pull"
    with pytest.raises(ValueError):
        resolve("ollama_pull", {"model": "bad name"})
    with pytest.raises(ValueError):
        resolve("ollama_status", {"extra": 1})


def test_status_file_round_trip(tmp_path):
    write_status(Path(tmp_path), state="pulling", detail="Pulling llama3.2:3b.", chat_model="llama3.2:3b", embed_model="nomic-embed-text")
    doc = json.loads((tmp_path / "status.json").read_text())
    assert doc["state"] == "pulling"
    assert doc["detail"].startswith("Pulling")
