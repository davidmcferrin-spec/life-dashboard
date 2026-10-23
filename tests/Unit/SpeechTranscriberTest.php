<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Speech\SpeechEngine;
use Dashboard\Speech\SpeechTranscriber;
use Dashboard\Speech\SpeechUnavailableException;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class SpeechTranscriberTest extends TestCase
{
    public function testInjectedEngineReturnsTextAndDropsTheTempFile(): void
    {
        $box = new \stdClass();
        $box->path = '';
        $engine = new class($box) implements SpeechEngine {
            public function __construct(private \stdClass $box) {}

            public function transcribeFile(string $path): string
            {
                $this->box->path = $path;

                return '  hello there  ';
            }
        };

        $out = (new SpeechTranscriber($engine))->transcribe('abc', 'audio/webm');
        self::assertSame(['text' => 'hello there'], $out);
        self::assertNotSame('', $box->path);
        self::assertFileDoesNotExist($box->path);
        self::assertDirectoryDoesNotExist(dirname($box->path));
    }

    public function testTempDirIsRemovedWhenTheEngineThrows(): void
    {
        $marker = 'SECRET-AUDIO-BYTES-XYZ';
        $box = new \stdClass();
        $box->path = '';
        $engine = new class($box) implements SpeechEngine {
            public function __construct(private \stdClass $box) {}

            public function transcribeFile(string $path): string
            {
                $this->box->path = $path;
                $bytes = file_get_contents($path);
                throw new \RuntimeException('boom ' . (is_string($bytes) ? $bytes : ''));
            }
        };

        try {
            (new SpeechTranscriber($engine))->transcribe($marker, 'audio/wav');
            self::fail('engine failure was accepted');
        } catch (SpeechUnavailableException $e) {
            self::assertSame(SpeechTranscriber::UNAVAILABLE, $e->getMessage());
            self::assertStringNotContainsString($marker, $e->getMessage());
            self::assertStringNotContainsString('boom', $e->getMessage());
        }
        self::assertNotSame('', $box->path);
        self::assertDirectoryDoesNotExist(dirname($box->path));
    }

    public function testNullEngineDoesNotEchoAudio(): void
    {
        $marker = 'SECRET-AUDIO-BYTES-XYZ';
        try {
            (new SpeechTranscriber(null))->transcribe($marker, 'audio/webm');
            self::fail('missing engine returned text');
        } catch (SpeechUnavailableException $e) {
            self::assertSame(SpeechTranscriber::UNAVAILABLE, $e->getMessage());
            self::assertStringNotContainsString($marker, $e->getMessage());
        }
    }

    public function testEmptyAndOversizeAudioAreRejected(): void
    {
        $engine = new class implements SpeechEngine {
            public function transcribeFile(string $path): string
            {
                return 'nope';
            }
        };
        $transcriber = new SpeechTranscriber($engine);
        try {
            $transcriber->transcribe('', 'audio/webm');
            self::fail('empty audio was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('audio', $e->fields());
        }
        try {
            $transcriber->transcribe(str_repeat('a', SpeechTranscriber::MAX_BYTES + 1), 'audio/webm');
            self::fail('oversize audio was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('audio', $e->fields());
        }
    }

    public function testJsonAudioDecodesWithoutKeepingACopyInTheError(): void
    {
        $parsed = SpeechTranscriber::bytesFromJson(json_encode([
            'audio_base64' => base64_encode('hi'),
            'mime' => 'audio/wav',
        ], JSON_THROW_ON_ERROR));
        self::assertSame('hi', $parsed['bytes']);
        self::assertSame('audio/wav', $parsed['mime']);

        try {
            SpeechTranscriber::bytesFromJson('{"audio_base64":"@@@@"}');
            self::fail('bad base64 was accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('audio', $e->fields());
            self::assertStringNotContainsString('@@@@', $e->getMessage());
        }
    }

    public function testSpeechAndFeaturesUseTheWidgetsScope(): void
    {
        self::assertSame('widgets:write', Scope::forPath('/api/v1/speech/transcribe', true));
        self::assertSame('widgets:read', Scope::forPath('/api/v1/speech/transcribe', false));
        self::assertSame('widgets:read', Scope::forPath('/api/v1/features', false));
        self::assertSame('widgets:write', Scope::forPath('/api/v1/features', true));

        $src = file_get_contents(dirname(__DIR__, 2) . '/src/Api/V1/ApiV1.php');
        self::assertIsString($src);
        self::assertSame(1, substr_count($src, '/api/v1/speech/transcribe'));
    }
}
