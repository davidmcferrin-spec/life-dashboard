<?php

declare(strict_types=1);

namespace Dashboard\Speech;

interface SpeechEngine
{
    public function transcribeFile(string $path): string;
}
