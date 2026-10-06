<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * Input validation failure with per-field messages (rendered as HTTP 422).
 */
final class ValidationException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $fields field => message
     */
    public function __construct(string $message, private readonly array $fields = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @param array<string, string> $fields */
    public static function withFields(array $fields): self
    {
        return new self(implode(' ', $fields), $fields);
    }
}
