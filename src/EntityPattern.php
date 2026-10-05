<?php

declare(strict_types=1);

namespace LoomContext;

class EntityPattern
{
    public function __construct(
        public string $kind,
        public string $regex,
        public bool $lower = false,
    ) {}
}
