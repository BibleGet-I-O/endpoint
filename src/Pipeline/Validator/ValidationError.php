<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Validator;

final class ValidationError
{
    public function __construct(
        public readonly string $message,
        public readonly int $position = -1,
    ) {
    }
}
