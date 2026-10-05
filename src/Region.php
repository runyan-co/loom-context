<?php

declare(strict_types=1);

namespace LoomContext;

/**
 * A rectangle of a saved frame, in that frame's pixels, given on the command line as "x,y,width,height".
 */
readonly class Region
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {}

    public static function parse(string $value): self
    {
        $parts = array_map(intval(...), explode(',', $value));

        if (count($parts) !== 4 || min($parts) < 0 || $parts[2] < 1 || $parts[3] < 1) {
            throw new Failure(ExitCode::BadInput, 'invalid region');
        }

        return new self(...$parts);
    }

    public function fitsWithin(int $width, int $height): bool
    {
        return $this->x + $this->width <= $width && $this->y + $this->height <= $height;
    }

    /**
     * @return list<int>
     */
    public function toArray(): array
    {
        return [$this->x, $this->y, $this->width, $this->height];
    }
}
