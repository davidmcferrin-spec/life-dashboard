<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Audit\AuditLog;
use Dashboard\Files\FileStorage;
use Dashboard\Knowledge\PageImageService;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

final class VaultImageTest extends PgsqlTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private int $user = 0;

    private PageImageService $images;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->images = new PageImageService($this->db, new AuditLog($this->db));
    }

    protected function tearDown(): void
    {
        $dir = FileStorage::vaultDirectory($this->user);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir);
        }
        parent::tearDown();
    }

    public function testUploadIsPrivateAndSvgIsRefused(): void
    {
        $image = $this->images->fromBase64($this->user, [
            'filename' => 'pixel.png',
            'content_base64' => self::PNG,
            'alt' => 'One pixel',
        ]);
        self::assertSame('/api/v1/pages/images/' . $image['id'], $image['url']);
        self::assertSame('![One pixel](' . $image['url'] . ')', $image['markdown']);
        $meta = $this->images->stream($this->user, $image['id']);
        self::assertSame('image/png', $meta['mime']);
        self::assertStringStartsWith("\x89PNG", (string) file_get_contents($meta['path']));

        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['bea', 'bea@x', 'h']
        );
        $other = (int) $this->db->lastInsertId();
        try {
            $this->images->stream($other, $image['id']);
            self::fail('another user must not read the image');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }

        try {
            $this->images->fromBase64($this->user, [
                'filename' => 'note.svg',
                'content_base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            ]);
            self::fail('svg must be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('JPEG', $e->getMessage());
        }
    }
}
