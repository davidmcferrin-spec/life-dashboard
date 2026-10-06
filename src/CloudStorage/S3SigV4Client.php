<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\Support\HttpClient;

/**
 * Minimal S3 SigV4 client for ListObjectsV2, PutObject, GetObject, DeleteObject.
 */
final class S3SigV4Client
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $endpoint,
        private readonly bool $pathStyle,
    ) {}

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function listObjectsPage(string $bucket, string $prefix, ?string $continuationToken = null): array
    {
        $query = 'list-type=2&prefix=' . rawurlencode($prefix);
        if ($continuationToken !== null && $continuationToken !== '') {
            $query .= '&continuation-token=' . rawurlencode($continuationToken);
        }

        return $this->request('GET', $bucket, '/' . $bucket, $query, '', 'application/xml', []);
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function listObjects(string $bucket, string $prefix, ?string $delimiter = '/'): array
    {
        $query = 'list-type=2&prefix=' . rawurlencode($prefix);
        if ($delimiter !== null) {
            $query .= '&delimiter=' . rawurlencode($delimiter);
        }

        return $this->request('GET', $bucket, '/' . $bucket, $query, '', 'application/xml', []);
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function putObject(string $bucket, string $key, string $body, string $contentType): array
    {
        return $this->request('PUT', $bucket, '/' . $key, '', $body, $contentType, [
            'content-md5' => base64_encode(md5($body, true)),
        ]);
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function getObject(string $bucket, string $key): array
    {
        return $this->request('GET', $bucket, '/' . $key, '', '', 'application/octet-stream', []);
    }

    /**
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function deleteObject(string $bucket, string $key): array
    {
        return $this->request('DELETE', $bucket, '/' . $key, '', '', 'application/octet-stream', []);
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function request(
        string $method,
        string $bucket,
        string $canonicalUri,
        string $query,
        string $body,
        string $contentType,
        array $extraHeaders,
    ): array {
        $now      = gmdate('Ymd\THis\Z');
        $date     = gmdate('Ymd');
        $host     = $this->hostFor($bucket);
        $payload  = hash('sha256', $body);
        $headers  = array_merge([
            'host'                 => $host,
            'x-amz-content-sha256' => $payload,
            'x-amz-date'           => $now,
            'content-type'         => $contentType,
        ], $extraHeaders);

        if (isset($headers['content-md5'])) {
            $headers['content-md5'] = $headers['content-md5'];
        }

        ksort($headers);
        $signedHeaderNames = implode(';', array_keys($headers));
        $canonicalHeaders  = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
        }

        $canonicalQuery = $this->canonicalQueryString($query);
        $canonicalRequest = implode("\n", [
            $method,
            $this->uriEncodePath($canonicalUri),
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaderNames,
            $payload,
        ]);

        $scope            = "{$date}/{$this->region}/s3/aws4_request";
        $stringToSign     = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signingKey       = $this->signingKey($date);
        $signature        = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization    = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaderNames . ', Signature=' . $signature;

        $httpHeaders = [
            'Authorization'        => $authorization,
            'X-Amz-Content-Sha256' => $payload,
            'X-Amz-Date'           => $now,
            'Content-Type'         => $contentType,
        ];
        foreach ($extraHeaders as $k => $v) {
            $httpHeaders[$this->headerCase($k)] = $v;
        }

        $url = $this->urlFor($bucket, $canonicalUri, $query);
        $http = new HttpClient();

        return $http->request($method, $url, $body !== '' ? $body : null, $httpHeaders, 120);
    }

    private function hostFor(string $bucket): string
    {
        $endpoint = rtrim($this->endpoint, '/');
        $parsed   = parse_url($endpoint);
        $host     = $parsed['host'] ?? $endpoint;

        if ($this->pathStyle) {
            return $host;
        }

        return $bucket . '.' . $host;
    }

    private function urlFor(string $bucket, string $uri, string $query): string
    {
        $endpoint = rtrim($this->endpoint, '/');
        if ($this->pathStyle) {
            $base = $endpoint . '/' . $bucket . $uri;
        } else {
            $parsed = parse_url($endpoint);
            $scheme = $parsed['scheme'] ?? 'https';
            $host   = $bucket . '.' . ($parsed['host'] ?? $endpoint);
            $base   = $scheme . '://' . $host . $uri;
        }

        return $query !== '' ? $base . '?' . $query : $base;
    }

    private function signingKey(string $date): string
    {
        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function canonicalQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }
        parse_str($query, $parts);
        ksort($parts);
        $pairs = [];
        foreach ($parts as $k => $v) {
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }

        return implode('&', $pairs);
    }

    private function uriEncodePath(string $path): string
    {
        $segments = explode('/', $path);
        $encoded  = array_map(static fn (string $s): string => rawurlencode($s), $segments);

        return implode('/', $encoded);
    }

    private function headerCase(string $name): string
    {
        return implode('-', array_map(static fn (string $p): string => ucfirst(strtolower($p)), explode('-', $name)));
    }
}
