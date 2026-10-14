<?php

declare(strict_types=1);

namespace Dashboard\Support;

/** Authenticated, but the token's scopes do not cover this call (HTTP 403). */
final class ForbiddenException extends \RuntimeException
{
}
