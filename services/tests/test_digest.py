"""Parser, dedupe, edit detection, and per-source errors for Grok Digest."""

from pathlib import Path

from lifedash.digest_http import FetchResult, UrlRejected, fetch_with_backoff
from lifedash.digest_parse import content_hash, discover_feed, interpret, parse_feed, parse_html, plan_changes, read_page
from lifedash.digest_sync import load_seeds, run_source

FIXTURES = Path(__file__).parent / "fixtures" / "digest"


def _html(name: str) -> str:
    return (FIXTURES / name).read_text(encoding="utf-8")


def test_changelog_headings_keep_dates_and_subsections():
    entries = parse_html(_html("changelog.html"), "https://notes.example.com/changelog")
    assert [entry["entry_key"] for entry in entries] == ["harbor-notes", "north-lights"]
    assert entries[0]["published_on"] == "2026-10-02"
    assert "quiet bridge" in entries[0]["summary"]
    assert "under a minute" in entries[0]["summary"]
    assert "Command Palette" not in entries[0]["summary"]
    assert entries[0]["title"] == "Harbor Notes 1.2"
    assert entries[0]["url"] == "https://notes.example.com/changelog/harbor-notes"


def test_version_notes_use_anchors_and_drop_duplicate_dom():
    entries = parse_html(_html("version-notes.html"), "https://notes.example.com/ide")
    assert [entry["title"] for entry in entries] == ["1.2", "1.1"]
    assert entries[0]["published_on"] == "2026-10-02"
    assert entries[0]["entry_key"] == "oct-2-2026"
    assert "Get Started" not in " ".join(entry["summary"] for entry in entries)


def test_featured_note_stays_ahead_of_the_list():
    entries = parse_html(_html("featured.html"), "https://notes.example.com/news")
    assert [entry["title"] for entry in entries] == [
        "Introducing Sample Ensemble",
        "North Harbor Lights",
        "Harbor Notes 1.2",
    ]
    assert "All posts" not in entries[0]["summary"]


def test_month_index_uses_the_item_headings():
    entries = parse_html(_html("month-notes.html"), "https://notes.example.com/months")
    assert [entry["title"] for entry in entries] == ["Harbor Notes 1.2", "North Harbor Lights"]
    assert "October" not in [entry["title"] for entry in entries]
    assert entries[0]["published_on"].endswith("-10-01")
    assert entries[1]["published_on"].endswith("-09-01")
    assert entries[0]["published_on"] > entries[1]["published_on"]
    assert "October" not in entries[0]["summary"]


def test_feed_dedupes_identical_items():
    mode, entries = parse_feed(_html("feed.xml"), "https://notes.example.com/feed")
    assert mode == "rss"
    assert len(entries) == 1
    assert entries[0]["entry_key"] == "harbor-1"
    assert entries[0]["published_on"] == "2026-10-02"


def test_advertised_feed_is_preferred():
    page = _html("with-feed.html")
    assert discover_feed(page, "https://notes.example.com/notes") == "https://notes.example.com/harbor.xml"

    def fetch_feed(url: str) -> dict:
        assert url.endswith("/harbor.xml")
        return {"status": 200, "body": _html("feed.xml"), "content_type": "application/rss+xml"}

    parsed = read_page(200, page, "text/html", "https://notes.example.com/notes", None, fetch_feed)
    assert parsed["parse_mode"] == "rss"
    assert parsed["entries"][0]["title"] == "Harbor Notes 1.2"


def test_plain_page_falls_back_to_a_hash_entry():
    body = _html("plain.html")
    first = interpret(200, body, "text/html", "https://notes.example.com/plain", None)
    assert first["parse_mode"] == "hash"
    assert first["entries"][0]["entry_key"] == "page"
    assert "North Harbor Lights" in first["entries"][0]["summary"]
    again = interpret(200, body, "text/html", "https://notes.example.com/plain", first["content_hash"])
    assert again["entries"] == []


def test_shell_and_block_need_attention():
    shell = interpret(200, _html("shell.html"), "text/html", "https://notes.example.com/app", None)
    assert shell["status"] == "needs_attention"
    assert "browser" in shell["error"].lower()
    assert shell["entries"] == []
    blocked = interpret(403, _html("blocked.html"), "text/html", "https://x.example.com/changelog", "abc")
    assert blocked["error"] == "Cloudflare blocked the fetch (HTTP 403)."
    assert blocked["content_hash"] == "abc"


def test_plan_changes_inserts_skips_and_detects_edits():
    original = parse_html(_html("changelog.html"), "https://notes.example.com/changelog")
    existing = {original[0]["entry_key"]: original[0]["content_hash"]}
    same = plan_changes(existing, [original[0]])
    assert same == []
    edited = {**original[0], "summary": "Sample Ensemble rewrote the bridge.", "content_hash": content_hash("changed")}
    ops = plan_changes(existing, [edited, original[1]])
    assert [op["op"] for op in ops] == ["update", "insert"]
    assert plan_changes(existing, [original[0], original[0]]) == []


def test_run_source_keeps_a_blocked_page_from_raising():
    def fetch(url, etag, modified):
        assert etag == "old"
        return FetchResult(status=403, body=_html("blocked.html"), content_type="text/html")

    result = run_source({"url": "https://x.example.com/bot", "etag": "old", "entries": {}}, fetch)
    assert result["status"] == "needs_attention"
    assert "Cloudflare" in result["error"]
    assert result["ops"] == []


def test_run_source_timeout_is_a_source_error():
    def fetch(url, etag, modified):
        raise TimeoutError("slow")

    result = run_source({"url": "https://notes.example.com/slow", "entries": {}}, fetch)
    assert result["error"] == "The request timed out."
    assert result["ops"] == []


def test_backoff_retries_then_returns_the_page():
    calls = {"n": 0}

    def transport(url, headers):
        calls["n"] += 1
        assert headers["If-None-Match"] == "abc"
        if calls["n"] == 1:
            return FetchResult(status=503, headers={"Retry-After": "1"}, body="busy")
        return FetchResult(status=200, headers={"ETag": "abc"}, body=_html("plain.html"), content_type="text/html")

    slept = []
    result = fetch_with_backoff(transport, "https://example.com/plain", "abc", None, slept.append)
    assert calls["n"] == 2
    assert slept == [1.0]
    assert result.status == 200


def test_private_url_is_rejected_without_a_request():
    def transport(url, headers):
        raise AssertionError("transport should not run")

    result = fetch_with_backoff(transport, "https://127.0.0.1/secret", None, None)
    assert isinstance(result, FetchResult)
    assert result.error == "That host is not fetched."


def test_seed_list_uses_the_resolved_docs_url():
    urls = {row["url"] for row in load_seeds()}
    assert "https://docs.x.ai/developers/release-notes" in urls
    assert "https://x.ai/docs/release-notes" not in urls
    assert "https://x.ai/changelog/bot" in urls
    assert len(load_seeds()) == 8
