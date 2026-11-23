<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use Dashboard\Support\HttpClient;

final class StreamSheetTransport implements SheetTransport
{
    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        try {
            $response = $this->http->request($method, $url, $body, $headers, 25);
        } catch (\RuntimeException) {
            throw SheetException::make('Google Sheets did not answer.');
        }

        return [
            'status' => $response['status'],
            'body'   => $response['body'],
        ];
    }
}
