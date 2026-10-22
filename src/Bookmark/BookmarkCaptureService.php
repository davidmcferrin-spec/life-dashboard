<?php

declare(strict_types=1);

namespace Dashboard\Bookmark;

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

final class BookmarkCaptureService
{
    private const PREF_KEY    = 'bookmark_capture_token';
    private const TTL_SECONDS = 2_592_000; // 30 days

    public function __construct(
        private readonly PreferenceService $prefs,
        private readonly BookmarkService $bookmarks,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self(
            new PreferenceService($db),
            BookmarkService::fromDatabase($db),
        );
    }

    public function ensureToken(int $userId): string
    {
        $data = $this->loadTokenData($userId);
        if ($data !== null) {
            return $data['token'];
        }

        return $this->rotateToken($userId);
    }

    public function rotateToken(int $userId): string
    {
        $token = $userId . '.' . bin2hex(random_bytes(16));

        $this->prefs->set($userId, self::PREF_KEY, json_encode([
            'token'      => $token,
            'expires_at' => time() + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR));

        return $token;
    }

    public function validateToken(string $token): ?int
    {
        if (!preg_match('/^(\d+)\.([a-f0-9]{32})$/', trim($token), $matches)) {
            return null;
        }

        $userId = (int) $matches[1];
        $data   = $this->loadTokenData($userId);

        if ($data === null || !hash_equals($data['token'], trim($token))) {
            return null;
        }

        return $userId;
    }

    public function bookmarkletHref(int $userId): string
    {
        $token = $this->ensureToken($userId);
        $base  = \Dashboard\Support\PublicUrl::base();
        if ($base === '') {
            $base = $this->requestOrigin();
        }

        $js = <<<JS
javascript:(function(){var b='{$base}',t='{$token}',d=document,u=location.href,title=(d.title||'').trim()||u,href=null,links=d.querySelectorAll('link[rel*="icon"]');for(var i=0;i<links.length;i++){href=links[i].href;break;}if(!href){href=location.origin+'/favicon.ico';}function done(msg){alert(msg);}function fail(){done('Could not capture icon from this page.');}fetch(href).then(function(r){if(!r.ok){throw new Error();}return r.blob();}).then(function(blob){if(!blob||blob.size<1||blob.size>1048576){throw new Error();}var reader=new FileReader();reader.onload=function(){var f=d.createElement('form');f.method='POST';f.action=b+'/api/bookmarks/capture-from-page';f.target='_blank';function add(n,v){var x=d.createElement('input');x.type='hidden';x.name=n;x.value=v;f.appendChild(x);}add('capture_token',t);add('page_url',u);add('title',title);add('favicon_base64',reader.result.split(',')[1]||'');d.body.appendChild(f);f.submit();setTimeout(function(){f.remove();},1000);done('Sending icon to your dashboard…');};reader.onerror=fail;reader.readAsDataURL(blob);}).catch(fail);})();
JS;

        return preg_replace('/\s+/', ' ', trim($js)) ?? '';
    }

    /**
     * @param array<string, mixed>|null $file $_FILES entry
     * @return array<string, mixed>
     */
    public function captureFromPage(
        int $userId,
        string $pageUrl,
        string $title,
        ?array $file,
        ?string $faviconBase64 = null,
        ?int $bookmarkId = null,
    ): array {
        $pageUrl = trim($pageUrl);
        $title   = trim($title);

        if ($pageUrl === '') {
            throw new \InvalidArgumentException('Page URL is required.');
        }

        if ($bookmarkId !== null && $bookmarkId > 0) {
            $row = $this->bookmarks->findBookmarkRow($bookmarkId, $userId);
            if ($row === null) {
                throw new \InvalidArgumentException('Bookmark not found.');
            }
        } else {
            $existing = $this->bookmarks->findBookmarkByUrl($userId, $pageUrl);
            if ($existing !== null) {
                $bookmarkId = (int) $existing['id'];
            } else {
                if ($title === '') {
                    $title = $this->titleFromUrl($pageUrl);
                }

                $created    = $this->bookmarks->createBookmark($userId, $title, $pageUrl, null, null);
                $bookmarkId = (int) $created['id'];
            }
        }

        if (is_array($file)) {
            return $this->bookmarks->uploadFavicon($bookmarkId, $userId, $file);
        }

        if ($faviconBase64 === null || trim($faviconBase64) === '') {
            throw new \InvalidArgumentException('No icon file received.');
        }

        $bytes = base64_decode(trim($faviconBase64), true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('Invalid icon data.');
        }

        return $this->bookmarks->uploadFaviconBytes($bookmarkId, $userId, $bytes, null);
    }

    /**
     * @return array{token: string, expires_at: int}|null
     */
    private function loadTokenData(int $userId): ?array
    {
        $raw = $this->prefs->get($userId, self::PREF_KEY);
        if ($raw === null || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['token']) || empty($data['expires_at'])) {
            return null;
        }

        if ((int) $data['expires_at'] < time()) {
            return null;
        }

        return [
            'token'      => (string) $data['token'],
            'expires_at' => (int) $data['expires_at'],
        ];
    }

    private function requestOrigin(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    private function titleFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $host  = $parts['host'] ?? $url;

        return preg_replace('/^www\./', '', strtolower($host)) ?: 'Bookmark';
    }
}
