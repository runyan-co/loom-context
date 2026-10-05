<?php

declare(strict_types=1);

namespace LoomContext;

class Log
{
    public static function line(string $message): void
    {
        fwrite(STDERR, "[loom-context] {$message}\n");
    }
}
