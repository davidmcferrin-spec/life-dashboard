<?php

declare(strict_types=1);

namespace Dashboard\Hermes;

/** Per-user Hermes session and run ids. Values are empty when unset. */
interface ChatMemory
{
    public function read(int $userId, string $key): string;

    public function write(int $userId, string $key, string $value): void;
}
