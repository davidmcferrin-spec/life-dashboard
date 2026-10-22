"""Spotify token key file. Same rules as the PHP helper, with no database."""

from pathlib import Path

from lifedash.token_key import material


def test_existing_file_wins_over_a_later_explicit_key(tmp_path: Path):
    path = tmp_path / "spotify-token.key"
    first = material(path, "explicit-token-key", "app-secret", False)
    assert first == "explicit-token-key"
    assert material(path, "a-different-key", "app-secret", False) == "explicit-token-key"
    assert "\n" not in path.read_text(encoding="utf-8").strip()


def test_accounts_keep_the_app_secret_when_no_key_was_saved(tmp_path: Path):
    path = tmp_path / "spotify-token.key"
    chosen = material(path, None, "app-secret-already-sealing-tokens", True)
    assert chosen == "app-secret-already-sealing-tokens"


def test_a_new_install_generates_a_key(tmp_path: Path):
    path = tmp_path / "nested" / "spotify-token.key"
    chosen = material(path, "CHANGE_ME", "", False)
    assert len(chosen) == 64
    assert path.read_text(encoding="utf-8").strip() == chosen
