<?php

declare(strict_types=1);

namespace LoomContext;

/**
 * Why a frame was captured, in the order the frame budget is spent: a narrated moment outranks a scene cut,
 * which outranks the fixed tick.
 */
enum FrameReason: string
{
    case Say = 'say';
    case Cut = 'cut';
    case Tick = 'tick';

    public function priority(): int
    {
        return match ($this) {
            self::Say => 0,
            self::Cut => 1,
            self::Tick => 2,
        };
    }
}
