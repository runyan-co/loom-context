<?php

declare(strict_types=1);

namespace LoomContext;

class Frame
{
    public function __construct(
        public float $seconds,
        public FrameReason $reason,
        public string $path = '',
        public string $dhash = '',
        public int $mean = 0,
        public int $bytes = 0,
    ) {}

    /**
     * @return array{ts: float, reason: string, path: string, dhash: string, mean: int, bytes: int}
     */
    public function toArray(): array
    {
        return [
            'ts' => $this->seconds,
            'reason' => $this->reason->value,
            'path' => $this->path,
            'dhash' => $this->dhash,
            'mean' => $this->mean,
            'bytes' => $this->bytes,
        ];
    }

    public function fileName(): string
    {
        return sprintf('f-%s-%s.jpg', str_replace(':', '', Transcript::clock($this->seconds)), $this->reason->value);
    }
}
