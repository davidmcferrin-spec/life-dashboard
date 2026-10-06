from pathlib import Path

import pytest

from lifedash.config import Config, ConfigError, find_env_file, parse_env_text

SAMPLE = """
# comment
APP_ENV=production          # inline comment
APP_URL=https://dash.example
QUOTED="has # hash inside quotes"
SINGLE='single'
EMPTY=
NOEQUALS
  SPACED_KEY  =  spaced value
URL_WITH_HASH=https://x/#frag
"""


def test_parse_matches_php_rules():
    v = parse_env_text(SAMPLE)
    assert v["APP_ENV"] == "production"
    assert v["APP_URL"] == "https://dash.example"
    # PHP strips " #..." before unquoting, so a quoted value containing " #" is cut too.
    assert v["QUOTED"] == '"has'
    assert v["SINGLE"] == "single"
    assert v["EMPTY"] == ""
    assert "NOEQUALS" not in v
    assert v["SPACED_KEY"] == "spaced value"
    assert v["URL_WITH_HASH"] == "https://x/#frag", "only space-hash starts a comment"


def test_file_wins_over_environment(tmp_path: Path):
    env = tmp_path / ".env"
    env.write_text("DB_NAME=from_file\n")
    cfg = Config.load(environ={"DB_NAME": "from_env", "DB_HOST": "envhost"}, env_file=env)
    assert cfg.get("DB_NAME") == "from_file"
    assert cfg.get("DB_HOST") == "envhost", "environment fills keys the file lacks"


def test_accessors(tmp_path: Path):
    env = tmp_path / ".env"
    env.write_text("A=yes\nB=0\nN=42\nBAD=x\nE=\n")
    cfg = Config.load(environ={}, env_file=env)
    assert cfg.bool("A") is True
    assert cfg.bool("B") is False
    assert cfg.bool("MISSING", default=True) is True
    assert cfg.int("N") == 42
    assert cfg.int("MISSING", 7) == 7
    assert cfg.maybe("E") is None
    assert cfg.get("E", "d") == "d"
    with pytest.raises(ConfigError):
        cfg.get("MISSING")
    with pytest.raises(ConfigError):
        cfg.int("BAD")


def test_explicit_env_file_must_exist(tmp_path: Path):
    with pytest.raises(ConfigError):
        find_env_file({"LIFEDASH_ENV_FILE": str(tmp_path / "nope.env")})
    f = tmp_path / "x.env"
    f.write_text("A=1\n")
    assert find_env_file({"LIFEDASH_ENV_FILE": str(f)}) == f
