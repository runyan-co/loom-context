<?php

declare(strict_types=1);

namespace LoomContext;

use RuntimeException;

class Failure extends RuntimeException
{
    public function __construct(
        public ExitCode $exit,
        string $message,
        public string $detail = '',
    ) {
        parent::__construct($message);
    }
}
