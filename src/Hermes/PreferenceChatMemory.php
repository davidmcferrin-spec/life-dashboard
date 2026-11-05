<?php

declare(strict_types=1);

namespace Dashboard\Hermes;

use Dashboard\Settings\PreferenceService;

/** Stores the Hermes session on the signed-in user's preferences. */
final class PreferenceChatMemory implements ChatMemory
{
    public function __construct(private readonly PreferenceService $preferences)
    {
    }

    public function read(int $userId, string $key): string
    {
        return $this->preferences->get($userId, $key, '') ?? '';
    }

    public function write(int $userId, string $key, string $value): void
    {
        $this->preferences->set($userId, $key, $value);
    }
}
