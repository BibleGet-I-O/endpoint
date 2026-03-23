<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Parser;

final class ParseException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $tokenPosition,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
