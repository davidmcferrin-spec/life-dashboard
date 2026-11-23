<?php

declare(strict_types=1);

namespace Dashboard\Stock;

interface StockSheetConfig
{
    /**
     * @param array<string, mixed> $input
     * @return array{spreadsheet_id: string, key_hint: string, configured: bool}
     */
    public function save(int $userId, array $input): array;

    /**
     * @return array{spreadsheet_id: string, key_hint: string, configured: bool}
     */
    public function view(int $userId): array;

    /**
     * @return array{spreadsheet_id: string, account: array<string, mixed>}|null
     */
    public function credentials(int $userId): ?array;

    /**
     * @return list<int>
     */
    public function userIds(): array;
}
