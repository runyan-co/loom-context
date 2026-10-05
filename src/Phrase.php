<?php

declare(strict_types=1);

namespace LoomContext;

class Phrase
{
    public function __construct(
        public float $seconds,
        public string $text,
        public ?string $speaker = null,
    ) {}
}
