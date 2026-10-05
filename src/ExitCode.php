<?php

declare(strict_types=1);

namespace LoomContext;

enum ExitCode: int
{
    case BadInput = 2;
    case Network = 3;
    case Auth = 4;
    case YtDlp = 5;
    case Ffmpeg = 6;
}
