<?php

declare(strict_types=1);

namespace LoomContext;

class Frame
{
    // Vision models scale a longer image down anyway, so a bigger frame only costs more.
    public const MaxLongEdge = 1568;

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

    /**
     * f-<mmss>-<reason>.jpg; a second frame of the same reason in the same second is -2, and so on.
     */
    public function fileName(int $copy = 1): string
    {
        $clock = str_replace(':', '', Transcript::clock($this->seconds));

        $suffix = $copy > 1 ? "-{$copy}" : '';

        return "f-{$clock}-{$this->reason->value}{$suffix}.jpg";
    }
}
