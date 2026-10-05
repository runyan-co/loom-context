<?php

declare(strict_types=1);

namespace Tests\Support;

class ScriptResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    /**
     * Every script prints one JSON object as its last stdout line; that line is what the agent reads.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $lines = explode("\n", trim($this->stdout));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    public function output(): string
    {
        return $this->stdout.$this->stderr;
    }
}
