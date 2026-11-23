<?php

declare(strict_types=1);

namespace Dashboard\Stock;

/**
 * Read-only Sheets API. The assertion is sent only to Google's token endpoint,
 * and the granted scope cannot edit the spreadsheet.
 */
final class GoogleSheetClient implements SheetReader
{
    public const READONLY_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(private readonly SheetTransport $transport) {}

    public function fetch(array $account, string $spreadsheetId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{20,80}$/', $spreadsheetId) !== 1) {
            throw SheetException::make('The spreadsheet id is not valid.');
        }
        $token = $this->accessToken($account);
        $grids = [];
        foreach (self::ranges() as $title => $range) {
            $grids[$title] = $this->values($token, $spreadsheetId, $title, $range);
        }

        return $grids;
    }

    /**
     * @param array<string, mixed> $account
     */
    private function accessToken(array $account): string
    {
        $email = (string) ($account['client_email'] ?? '');
        $key = (string) ($account['private_key'] ?? '');
        if ($email === '' || $key === '') {
            throw SheetException::make('The service account key is incomplete.');
        }
        $assertion = $this->assertion($email, $key);
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);
        $response = $this->transport->request('POST', self::TOKEN_URL, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ], $body);
        $decoded = json_decode($response['body'], true);
        $token = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($response['status'] >= 400 || $token === '') {
            throw SheetException::make('Google did not accept the service account.');
        }

        return $token;
    }

    /**
     * @return list<list<mixed>>
     */
    private function values(string $token, string $spreadsheetId, string $title, string $range): array
    {
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId)
            . '/values/' . rawurlencode($range)
            . '?valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING';
        $response = $this->transport->request('GET', $url, [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ], null);
        $decoded = json_decode($response['body'], true);
        if ($response['status'] >= 400) {
            $message = 'Google Sheets request failed.';
            if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
            }
            if ($title === 'Play Account' && $response['status'] === 400) {
                return [];
            }
            // An error from History is an unread tab, not an empty history.
            if ($title === 'History') {
                return [];
            }
            throw SheetException::make($message);
        }
        if (!is_array($decoded)) {
            throw SheetException::make('Google Sheets did not return JSON.');
        }
        $values = $decoded['values'] ?? [];
        if (!is_array($values)) {
            return [];
        }
        $rows = [];
        foreach ($values as $row) {
            $rows[] = is_array($row) ? array_values($row) : [];
        }

        return $rows;
    }

    private function assertion(string $email, string $privateKey): string
    {
        $now = time();
        $header = self::b64(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = self::b64([
            'iss'   => $email,
            'scope' => self::READONLY_SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]);
        $unsigned = $header . '.' . $claims;
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw SheetException::make('The service account key could not be read.');
        }
        $signature = '';
        if (!openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw SheetException::make('The service account key could not be signed.');
        }

        return $unsigned . '.' . self::b64raw($signature);
    }

    /**
     * @return array<string, string>
     */
    private static function ranges(): array
    {
        return [
            'Watchlist'    => 'Watchlist!A:Z',
            'Commodities'  => 'Commodities!A:Z',
            'Play Account' => "'Play Account'!A:E",
            // History Raw is a helper tab. This range is the History tab only.
            'History'      => 'History!A:Z',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function b64(array $data): string
    {
        return self::b64raw((string) json_encode($data, JSON_THROW_ON_ERROR));
    }

    private static function b64raw(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
