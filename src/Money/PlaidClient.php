<?php

declare(strict_types=1);

namespace Dashboard\Money;

/**
 * Plaid REST calls for one app. The secret is sent to Plaid and is not logged.
 */
final class PlaidClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $secret,
        private readonly string $environment,
        private readonly PlaidTransport $transport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function createLinkToken(string $clientUserId, ?string $accessToken = null): array
    {
        $body = [
            'client_name'   => 'Life Dashboard',
            'language'      => 'en',
            'country_codes' => ['US'],
            'user'          => ['client_user_id' => $clientUserId],
        ];
        if ($accessToken !== null && $accessToken !== '') {
            $body['access_token'] = $accessToken;
            $body['update'] = ['account_selection_enabled' => false];
        } else {
            $body['products'] = ['transactions'];
            $body['optional_products'] = ['liabilities'];
        }

        return $this->call('/link/token/create', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangePublicToken(string $publicToken): array
    {
        return $this->call('/item/public_token/exchange', ['public_token' => $publicToken]);
    }

    /**
     * @return array<string, mixed>
     */
    public function accounts(string $accessToken): array
    {
        return $this->call('/accounts/get', ['access_token' => $accessToken]);
    }

    /**
     * @return array<string, mixed>
     */
    public function balances(string $accessToken): array
    {
        return $this->call('/accounts/balance/get', ['access_token' => $accessToken]);
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionsSync(string $accessToken, ?string $cursor): array
    {
        $body = ['access_token' => $accessToken, 'count' => 100];
        if ($cursor !== null && $cursor !== '') {
            $body['cursor'] = $cursor;
        }

        return $this->call('/transactions/sync', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function liabilities(string $accessToken): array
    {
        return $this->call('/liabilities/get', ['access_token' => $accessToken]);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeItem(string $accessToken): array
    {
        return $this->call('/item/remove', ['access_token' => $accessToken]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $path, array $body): array
    {
        $body['client_id'] = $this->clientId;
        $body['secret'] = $this->secret;

        return $this->transport->post($this->host() . $path, $body);
    }

    public function host(): string
    {
        return match ($this->environment) {
            'sandbox'     => 'https://sandbox.plaid.com',
            'development' => 'https://development.plaid.com',
            'production'  => 'https://production.plaid.com',
            default       => throw new PlaidException('ENVIRONMENT', 'Unknown Plaid environment.'),
        };
    }

    public static function signedAmount(float $plaidAmount): float
    {
        return round(-1 * $plaidAmount, 2);
    }

    /**
     * @param array<string, mixed> $transaction
     */
    public static function categoryLabel(array $transaction): string
    {
        $primary = $transaction['personal_finance_category']['primary'] ?? null;
        if (is_string($primary) && trim($primary) !== '') {
            return self::label($primary);
        }
        $legacy = $transaction['category'][0] ?? null;
        if (is_string($legacy) && trim($legacy) !== '') {
            return self::label($legacy);
        }

        return 'Uncategorized';
    }

    public static function dashboardKind(string $type, string $subtype): string
    {
        $type = strtolower($type);
        $subtype = strtolower($subtype);
        if ($type === 'depository' && $subtype === 'savings') {
            return 'savings';
        }
        if ($type === 'depository') {
            return 'checking';
        }
        if ($type === 'credit') {
            return 'credit';
        }
        if ($type === 'investment') {
            return 'investment';
        }

        return 'other';
    }

    private static function label(string $raw): string
    {
        $text = ucwords(strtolower(str_replace('_', ' ', trim($raw))));

        return mb_substr($text, 0, 80);
    }
}
