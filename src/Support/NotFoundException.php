<?php

declare(strict_types=1);

namespace Dashboard\Support;

/** A resource does not exist or does not belong to the caller (rendered as 404). */
final class NotFoundException extends \RuntimeException
{
}
