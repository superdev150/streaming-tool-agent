<?php

declare(strict_types=1);

namespace Showcase\Agent\Model;

final class ToolCall
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
    ) {
    }
}
