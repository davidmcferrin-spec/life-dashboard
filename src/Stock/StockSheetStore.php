<?php

declare(strict_types=1);

namespace Dashboard\Stock;

interface StockSheetStore
{
    /**
     * @return list<array<string, mixed>>
     */
    public function watchlist(int $userId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function commodities(int $userId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function play(int $userId): array;

    /**
     * @return array<string, mixed>
     */
    public function status(int $userId): array;

    /**
     * @param list<array<string, mixed>> $watchlist
     * @param list<array<string, mixed>> $commodities
     * @param list<array<string, mixed>> $play
     * @param array<string, mixed> $status
     */
    public function replace(int $userId, array $watchlist, array $commodities, array $play, array $status): void;

    public function writeError(int $userId, string $message, string $attemptedAt): void;
}
