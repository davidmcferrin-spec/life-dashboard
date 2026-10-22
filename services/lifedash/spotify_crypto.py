"""AES-256-GCM blobs that match the PHP TokenCipher layout: base64(iv || tag || ciphertext)."""

from __future__ import annotations

import base64
import hashlib
import os

from cryptography.hazmat.primitives.ciphers.aead import AESGCM

IV_LEN = 12
TAG_LEN = 16


def key_bytes(secret: str) -> bytes:
    return hashlib.sha256(secret.encode("utf-8")).digest()


def seal(plaintext: str, key: bytes, iv: bytes | None = None) -> str:
    if len(key) != 32:
        raise ValueError("Spotify token key must be 32 bytes.")
    nonce = iv if iv is not None else os.urandom(IV_LEN)
    if len(nonce) != IV_LEN:
        raise ValueError("Spotify token IV must be 12 bytes.")
    sealed = AESGCM(key).encrypt(nonce, plaintext.encode("utf-8"), None)
    ciphertext, tag = sealed[:-TAG_LEN], sealed[-TAG_LEN:]
    return base64.b64encode(nonce + tag + ciphertext).decode("ascii")


def open_blob(blob: str, key: bytes) -> str:
    if len(key) != 32:
        raise ValueError("Spotify token key must be 32 bytes.")
    try:
        raw = base64.b64decode(blob, validate=True)
    except Exception as exc:
        raise ValueError("Could not read the stored Spotify token.") from exc
    if len(raw) < IV_LEN + TAG_LEN + 1:
        raise ValueError("Could not read the stored Spotify token.")
    nonce = raw[:IV_LEN]
    tag = raw[IV_LEN : IV_LEN + TAG_LEN]
    ciphertext = raw[IV_LEN + TAG_LEN :]
    try:
        plain = AESGCM(key).decrypt(nonce, ciphertext + tag, None)
    except Exception as exc:
        raise ValueError("Could not read the stored Spotify token.") from exc
    return plain.decode("utf-8")
