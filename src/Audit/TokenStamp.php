<?php

declare(strict_types=1);

namespace Dashboard\Audit;

/**
 * Token identity stored on an audit row. The secret never goes in here.
 */
final class TokenStamp
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function detail(?int $tokenId, ?string $tokenName, string $verb, array $extra = []): array
    {
        $detail = $extra;
        $detail['verb'] = $verb === 'write' ? 'write' : 'read';
        if ($tokenId !== null && $tokenId > 0) {
            $name = trim((string) $tokenName);
            $detail['token_id'] = $tokenId;
            $detail['token_name'] = $name === '' ? 'Agent' : mb_substr($name, 0, 64);
        }
        return $detail;
    }
}
