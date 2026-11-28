<?php

declare(strict_types=1);

namespace Dashboard\Host;

final class HostHttp
{
    /**
     * @param null|\Closure(string, bool): array{status: ?int, latency_ms: ?float, error: ?string, body: string} $fake
     */
    public function __construct(private readonly ?\Closure $fake = null) {}

    /**
     * @return array{status: ?int, latency_ms: ?float, error: ?string, body: string}
     */
    public function get(string $url, float $timeout = 4.0, bool $follow = true): array
    {
        if ($this->fake !== null) {
            return ($this->fake)($url, $follow);
        }
        if (!function_exists('curl_init')) {
            return ['status' => null, 'latency_ms' => null, 'error' => 'curl is not available', 'body' => ''];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => null, 'latency_ms' => null, 'error' => 'curl could not start', 'body' => ''];
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) min(2000, round($timeout * 1000)),
            CURLOPT_USERAGENT => 'lifedash-host',
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $latency = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            return ['status' => null, 'latency_ms' => null, 'error' => $error !== '' ? $error : 'request failed', 'body' => ''];
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $body = substr((string) $raw, $headerSize);
        if (strlen($body) > 262144) {
            $body = substr($body, 0, 262144);
        }

        return [
            'status' => $status > 0 ? $status : null,
            'latency_ms' => $latency,
            'error' => null,
            'body' => $body,
        ];
    }
}
