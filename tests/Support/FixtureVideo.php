<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class FixtureVideo
{
    /**
     * A recording that cuts from a white screen to colour bars to a navy screen to a moving test pattern:
     * three hard cuts, two static stretches.
     */
    public const ScreenRecording = ['color=c=white', 'smptebars', 'color=c=navy', 'testsrc2'];

    /** @var array<string, string> */
    private static array $built = [];

    public static function available(): bool
    {
        $finder = new ExecutableFinder;

        return $finder->find('ffmpeg') !== null && $finder->find('ffprobe') !== null;
    }

    /**
     * A 640x360, 10 fps clip that hard-cuts between the given lavfi sources, each on screen for $seconds.
     * A clip is built once per distinct recipe and reused after that, since every build is an ffmpeg encode.
     *
     * @param  list<string>  $sources
     */
    public static function path(array $sources, float $seconds): string
    {
        $recipe = implode('|', $sources).'@'.$seconds;

        return self::$built[$recipe] ??= self::build($sources, $seconds);
    }

    /**
     * @param  list<string>  $sources
     */
    private static function build(array $sources, float $seconds): string
    {
        $path = sys_get_temp_dir().'/loom-context-clip-'.bin2hex(random_bytes(6)).'.mp4';

        $inputs = [];

        foreach ($sources as $source) {
            $separator = str_contains($source, '=') ? ':' : '=';

            array_push($inputs, '-f', 'lavfi', '-i', $source.$separator.'s=640x360:r=10:d='.$seconds);
        }

        $streams = implode('', array_map(fn (int $index) => '['.$index.':v]', array_keys($sources)));

        $command = [
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            ...$inputs,
            '-filter_complex',
            $streams.'concat=n='.count($sources).':v=1:a=0,format=yuv420p[v]',
            '-map',
            '[v]',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            $path,
        ];

        $ffmpeg = new Process($command);

        $ffmpeg->run();

        if (! $ffmpeg->isSuccessful()) {
            throw new RuntimeException("ffmpeg could not build the fixture clip: {$ffmpeg->getErrorOutput()}");
        }

        register_shutdown_function(fn () => @unlink($path));

        return $path;
    }
}
