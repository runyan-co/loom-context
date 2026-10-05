<?php

declare(strict_types=1);

namespace LoomContext;

/**
 * Why a frame was captured, in the order the frame budget is spent: the end of the recording, a frame pulled on
 * request, a narrated moment, then a settled screen.
 */
enum FrameReason: string
{
    case End = 'end';
    case Pull = 'pull';
    case Say = 'say';
    case State = 'state';

    public function priority(): int
    {
        return match ($this) {
            self::End => 0,
            self::Pull => 1,
            self::Say => 2,
            self::State => 3,
        };
    }
}
