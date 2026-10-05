<?php

declare(strict_types=1);

namespace LoomContext;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Tool
{
    public static function find(string $name): ?string
    {
        return (new ExecutableFinder)->find($name);
    }

    /**
     * Runs yt-dlp or ffmpeg to completion. Symfony stops a process after a minute by default; a download or a
     * long recording takes what it takes, so there is no limit here.
     *
     * @param  list<string>  $command
     */
    public static function run(array $command): Process
    {
        $process = new Process($command);

        $process->setTimeout(null);

        $process->run();

        return $process;
    }

    public static function lastErrorLine(Process $process): string
    {
        $lines = explode("\n", trim($process->getErrorOutput()));

        return end($lines);
    }
}
