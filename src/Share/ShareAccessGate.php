<?php

declare(strict_types=1);

namespace Dashboard\Share;

use Dashboard\Clipboard\ClipboardShareRepository;
use Dashboard\Clipboard\ShareToken;
use Dashboard\CloudStorage\StorageShareRepository;
use Dashboard\CloudPad\CloudPadShareRepository;
use Dashboard\DB\Database;
use Dashboard\Files\FileShareRepository;

final class ShareAccessGate
{
    public static function fileShareAllowed(string $token, ?Database $db = null): bool
    {
        $token = ShareToken::normalize($token);
        if ($token === '' || !ShareToken::isValid($token)) {
            return false;
        }

        $db = $db ?? Database::getInstance();

        $local = new FileShareRepository($db);
        if ($local->tablesReady() && $local->findLinkByToken($token) !== null) {
            return true;
        }

        $cloud = new StorageShareRepository($db);
        if (!$cloud->tablesReady()) {
            return false;
        }

        $link = $cloud->findLinkByToken($token);
        if ($link === null) {
            return false;
        }

        return !empty($link['connection_active']);
    }

    public static function clipboardShareAllowed(string $token, ?Database $db = null): bool
    {
        $token = ShareToken::normalize($token);
        if ($token === '' || !ShareToken::isValid($token)) {
            return false;
        }

        $db   = $db ?? Database::getInstance();
        $repo = new ClipboardShareRepository($db);

        if (!$repo->tablesReady()) {
            return false;
        }

        return $repo->findLinkByToken($token) !== null;
    }

    public static function cloudpadShareAllowed(string $token, ?Database $db = null): bool
    {
        $token = ShareToken::normalize($token);
        if ($token === '' || !ShareToken::isValid($token)) {
            return false;
        }

        $db   = $db ?? Database::getInstance();
        $repo = new CloudPadShareRepository($db);

        if (!$repo->tablesReady()) {
            return false;
        }

        return $repo->findLinkByToken($token) !== null;
    }
}
