<?php

declare(strict_types=1);

namespace Dashboard\Speech;

use Dashboard\Support\ValidationException;

/**
 * Accepts one clip and returns text. The file is removed before the method returns.
 */
final class SpeechTranscriber
{
    public const MAX_BYTES = 8388608;

    public const UNAVAILABLE = 'Speech recognition is not installed on this server.';

    public function __construct(private readonly ?SpeechEngine $engine) {}

    public static function make(): self
    {
        return new self(WhisperCli::discover());
    }

    /**
     * @return array{text: string}
     */
    public function fromRequest(): array
    {
        $file = $_FILES['audio'] ?? null;
        if (is_array($file) && is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw ValidationException::withFields(['audio' => 'The audio upload did not finish.']);
            }
            $bytes = file_get_contents((string) $file['tmp_name']);
            $mime = (string) ($file['type'] ?? 'application/octet-stream');

            return $this->transcribe(is_string($bytes) ? $bytes : '', $mime);
        }

        $raw = file_get_contents('php://input');
        $raw = is_string($raw) ? $raw : '';
        $type = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        if (str_contains($type, 'application/json') || str_starts_with(ltrim($raw), '{')) {
            $parsed = self::bytesFromJson($raw);

            return $this->transcribe($parsed['bytes'], $parsed['mime']);
        }
        $mime = str_starts_with($type, 'audio/') || str_contains($type, 'application/octet-stream')
            ? $type
            : 'application/octet-stream';

        return $this->transcribe($raw, $mime);
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public static function bytesFromJson(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['audio_base64']) || !is_string($data['audio_base64'])) {
            throw ValidationException::withFields(['audio' => 'Send audio as a file or JSON.']);
        }
        $bytes = base64_decode($data['audio_base64'], true);
        if ($bytes === false) {
            throw ValidationException::withFields(['audio' => 'audio_base64 is not valid base64.']);
        }

        return [
            'bytes' => $bytes,
            'mime'  => isset($data['mime']) && is_string($data['mime']) && $data['mime'] !== '' ? $data['mime'] : 'audio/webm',
        ];
    }

    /**
     * @return array{text: string}
     */
    public function transcribe(string $bytes, string $mime): array
    {
        if ($bytes === '') {
            throw ValidationException::withFields(['audio' => 'Choose an audio file.']);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withFields(['audio' => 'Audio must be 8 MB or smaller.']);
        }
        if ($this->engine === null) {
            throw new SpeechUnavailableException(self::UNAVAILABLE);
        }

        $dir = sys_get_temp_dir() . '/ld-speech-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new SpeechUnavailableException(self::UNAVAILABLE);
        }
        $path = $dir . '/audio.' . self::extension($mime);
        try {
            if (file_put_contents($path, $bytes) === false) {
                throw new SpeechUnavailableException(self::UNAVAILABLE);
            }
            try {
                $text = $this->engine->transcribeFile($path);
            } catch (SpeechUnavailableException $e) {
                throw $e;
            } catch (\Throwable) {
                throw new SpeechUnavailableException(self::UNAVAILABLE);
            }

            return self::response($text);
        } finally {
            self::wipe($dir);
        }
    }

    /**
     * @return array{text: string}
     */
    public static function response(string $text): array
    {
        return ['text' => trim($text)];
    }

    private static function extension(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return match ($mime) {
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/ogg', 'audio/opus' => 'ogg',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            default => 'webm',
        };
    }

    private static function wipe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                if (is_dir($path)) {
                    self::wipe($path);
                } else {
                    unlink($path);
                }
            }
        }
        rmdir($dir);
    }
}
