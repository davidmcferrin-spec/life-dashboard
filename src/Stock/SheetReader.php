<?php

declare(strict_types=1);

namespace Dashboard\Stock;

interface SheetReader
{
    /**
     * @param array<string, mixed> $account
     * @return array<string, list<list<mixed>>>
     */
    public function fetch(array $account, string $spreadsheetId): array;
}
