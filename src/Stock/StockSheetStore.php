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

    /**
     * Upsert daily bars. A null field keeps the stored number.
     * Symbols and dates that are absent from $bars stay. An empty list changes nothing.
     *
     * @param list<array<string, mixed>> $bars
     */
    public function upsertBars(int $userId, array $bars): void;

    /**
     * One price per symbol for this hour. A quote with no price does not replace the stored hour.
     *
     * @param list<array<string, mixed>> $quotes
     */
    public function snapshot(int $userId, array $quotes, string $capturedAt): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function bars(int $userId, string $symbol): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function ticks(int $userId, string $symbol): array;
}
