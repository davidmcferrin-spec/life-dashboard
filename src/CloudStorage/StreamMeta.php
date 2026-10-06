<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

/**
 * Download payload from a cloud provider — either a temp file path or an open stream.
 */
final class StreamMeta
{
    /**
     * @param string|resource $pathOrStream Absolute temp file path or readable stream resource
     */
    public function __construct(
        private readonly mixed $pathOrStream,
        public readonly string $mime,
        public readonly string $filename,
        public readonly bool $inline,
        public readonly ?int $size = null,
    ) {}

    /**
     * Absolute path suitable for readfile(), or null when backed by a stream only.
     */
    public function readPath(): ?string
    {
        return is_string($this->pathOrStream) ? $this->pathOrStream : null;
    }

    /**
     * Full body bytes — reads from path or stream resource.
     */
    public function readBody(): string
    {
        if (is_string($this->pathOrStream)) {
            $body = file_get_contents($this->pathOrStream);

            if ($body === false) {
                throw new \RuntimeException('Could not read cloud download from temp file.');
            }

            return $body;
        }

        if (!is_resource($this->pathOrStream)) {
            throw new \RuntimeException('Invalid cloud download source.');
        }

        $body = stream_get_contents($this->pathOrStream);

        if ($body === false) {
            throw new \RuntimeException('Could not read cloud download from stream.');
        }

        return $body;
    }
}
