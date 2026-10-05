<?php

declare(strict_types=1);

namespace LoomContext;

class Entity
{
    public function __construct(
        public string $kind,
        public string $value,
        public float $seconds,
    ) {}
}
