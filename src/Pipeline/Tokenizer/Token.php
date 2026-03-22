<?php

declare(strict_types=1);

namespace BibleGet\Api\Pipeline\Tokenizer;

final class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
        public readonly int $position,
    ) {
    }

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }
}
