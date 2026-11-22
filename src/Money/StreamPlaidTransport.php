<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\Support\HttpClient;

final class StreamPlaidTransport implements PlaidTransport
{
    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    public function post(string $url, array $body): array
    {
        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        try {
            $response = $this->http->request(
                'POST',
                $url,
                $encoded,
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                25,
            );
        } catch (\RuntimeException $e) {
            throw new PlaidException('HTTP', 'Plaid did not answer.');
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new PlaidException('HTTP', 'Plaid did not return JSON.');
        }
        if ($response['status'] >= 400 || isset($decoded['error_code'])) {
            $code = (string) ($decoded['error_code'] ?? 'HTTP');
            $message = (string) ($decoded['error_message'] ?? 'Plaid request failed.');
            throw new PlaidException($code, self::safeMessage($code, $message));
        }

        return $decoded;
    }

    public static function safeMessage(string $code, string $message): string
    {
        $text = trim(preg_replace('/access-[A-Za-z0-9-]+/', '[redacted]', $message) ?? $message);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($text === '') {
            $text = $code;
        }

        return mb_substr($text, 0, 300);
    }
}
