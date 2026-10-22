"""Dashboard setup values win over the environment. No database connection."""

from lifedash.config import Config
from lifedash.runtime_config import RuntimeSettings, prefer
from lifedash.spotify_crypto import key_bytes, open_blob, seal


def test_prefer_uses_a_saved_value_then_the_environment():
    assert prefer(" from-db ", "from-env") == "from-db"
    assert prefer("  ", "from-env") == "from-env"
    assert prefer(None, None) is None


def test_runtime_rereads_a_saved_value_over_the_environment():
    config = Config(values={"MCP_PUBLIC_URL": "https://env.example/mcp", "SPOTIFY_SYNC_MINUTES": "10"})
    saved = {"MCP_PUBLIC_URL": "https://mcp.example.com/mcp", "SPOTIFY_SYNC_MINUTES": "15"}
    calls = {"n": 0}

    def reader():
        calls["n"] += 1
        return saved

    runtime = RuntimeSettings(config, reader, ttl=0)
    assert runtime.maybe("MCP_PUBLIC_URL") == "https://mcp.example.com/mcp"
    assert runtime.int("SPOTIFY_SYNC_MINUTES", 10) == 15
    saved["MCP_PUBLIC_URL"] = "https://next.example/mcp"
    assert runtime.maybe("MCP_PUBLIC_URL") == "https://next.example/mcp"
    assert calls["n"] == 3

    empty = RuntimeSettings(config, lambda: {}, ttl=0)
    assert empty.maybe("MCP_PUBLIC_URL") == "https://env.example/mcp"


def test_setup_blob_opens_with_the_same_key_layout():
    secret = "unit-test-app-secret-value"
    blob = seal('{"PUBLIC_BASE_URL":"https://dash.example.com"}', key_bytes(secret))
    assert "dash.example.com" not in blob
    opened = open_blob(blob, key_bytes(secret))
    assert opened == '{"PUBLIC_BASE_URL":"https://dash.example.com"}'
