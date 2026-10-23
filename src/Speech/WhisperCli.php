<?php

declare(strict_types=1);

namespace Dashboard\Speech;

/**
 * Local whisper only. No API key and no .env secret.
 * stderr is discarded because whisper prints filesystem paths there.
 */
final class WhisperCli implements SpeechEngine
{
    /**
     * @param list<string> $prefix
     */
    private function __construct(
        private readonly array $prefix,
        private readonly string $kind,
    ) {}

    public static function discover(): ?self
    {
        $model = self::model();
        foreach (['whisper-cli', 'whisper-cpp'] as $name) {
            $bin = self::which($name);
            if ($bin !== null && $model !== null) {
                return new self([$bin, '-m', $model, '-otxt', '-of'], 'cpp');
            }
        }
        if ($model !== null) {
            foreach (self::modelDirs() as $dir) {
                $main = $dir . '/main';
                if (is_file($main) && is_executable($main)) {
                    return new self([$main, '-m', $model, '-otxt', '-of'], 'cpp');
                }
            }
        }
        $python = self::which('whisper');
        if ($python !== null) {
            return new self([$python, '--model', 'base', '--output_format', 'txt', '--output_dir'], 'py');
        }

        return null;
    }

    public function transcribeFile(string $path): string
    {
        $dir = dirname($path);
        if ($this->kind === 'py') {
            $command = array_merge($this->prefix, [$dir, $path]);
            $txt = $dir . '/' . pathinfo($path, PATHINFO_FILENAME) . '.txt';
        } else {
            $base = $dir . '/out';
            $command = array_merge($this->prefix, [$base, '-f', $path]);
            $txt = $base . '.txt';
        }
        self::run($command);
        if (!is_file($txt)) {
            throw new SpeechUnavailableException(SpeechTranscriber::UNAVAILABLE);
        }
        $text = file_get_contents($txt);

        return is_string($text) ? trim($text) : '';
    }

    /**
     * @param list<string> $command
     */
    private static function run(array $command): void
    {
        $proc = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proc)) {
            throw new SpeechUnavailableException(SpeechTranscriber::UNAVAILABLE);
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new SpeechUnavailableException(SpeechTranscriber::UNAVAILABLE);
        }
    }

    private static function which(string $name): ?string
    {
        if (preg_match('/^[a-z0-9._-]+$/', $name) !== 1) {
            return null;
        }
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return null;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function model(): ?string
    {
        foreach (self::modelDirs() as $dir) {
            $files = array_merge(glob($dir . '/*.bin') ?: [], glob($dir . '/*.ggml') ?: []);
            $fallback = null;
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                if (str_contains(strtolower(basename($file)), 'ggml')) {
                    return $file;
                }
                $fallback ??= $file;
            }
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function modelDirs(): array
    {
        return ['/usr/share/whisper', '/var/lib/lifedash/models'];
    }
}
