<?php

declare(strict_types=1);

namespace LoomContext;

use Symfony\Component\Process\Process;

class Ffmpeg
{
    public static function ensureInstalled(): void
    {
        if (Tool::find('ffmpeg') === null || Tool::find('ffprobe') === null) {
            throw new Failure(
                ExitCode::Ffmpeg,
                'ffmpeg/ffprobe not found. Install with `brew install ffmpeg` (or your package manager).',
            );
        }
    }

    public static function duration(string $video): float
    {
        $probe = self::run([
            'ffprobe',
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'csv=p=0',
            $video,
        ]);

        return (float) trim($probe->getOutput());
    }

    /**
     * @return list<float>
     */
    public static function sceneCuts(string $video, float $threshold): array
    {
        // showinfo reports each selected frame on stderr; ffmpeg's exit code says nothing useful here.
        $scan = Tool::run([
            'ffmpeg',
            '-hide_banner',
            '-i',
            $video,
            '-vf',
            "select='gt(scene,{$threshold})',showinfo",
            '-f',
            'null',
            '-',
        ]);

        preg_match_all('/pts_time:([0-9.]+)/', $scan->getErrorOutput(), $times);

        return array_map(floatval(...), $times[1]);
    }

    public static function extract(string $video, float $seconds, string $destination, int $longEdge): void
    {
        self::run([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-ss',
            sprintf('%.3f', $seconds),
            '-i',
            $video,
            '-frames:v',
            '1',
            '-vf',
            "scale='min({$longEdge},iw)':-2",
            '-q:v',
            '4',
            $destination,
        ]);
    }

    /**
     * The image as 72 raw bytes: a 9x8 grayscale thumbnail, which is all a difference hash needs.
     */
    public static function grayThumbnail(string $image): string
    {
        $thumbnail = self::run([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-i',
            $image,
            '-vf',
            'scale=9:8:flags=area,format=gray',
            '-f',
            'rawvideo',
            '-',
        ]);

        return $thumbnail->getOutput();
    }

    /**
     * @param  list<string>  $images
     */
    public static function contactSheet(
        array $images,
        string $destination,
        int $columns = 6,
        int $tileWidth = 320,
    ): void {
        if ($images === []) {
            return;
        }

        $rows = (int) ceil(count($images) / $columns);

        $listing = sprintf('%s/contact-list.txt', dirname($destination));

        file_put_contents(
            $listing,
            implode('', array_map(fn (string $image) => sprintf("file '%s'\n", realpath($image)), $images)),
        );

        self::run([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $listing,
            '-vf',
            "scale={$tileWidth}:-2,tile={$columns}x{$rows}",
            '-frames:v',
            '1',
            '-q:v',
            '5',
            $destination,
        ]);

        unlink($listing);
    }

    /**
     * @param  list<string>  $command
     */
    private static function run(array $command): Process
    {
        $process = Tool::run($command);

        if (! $process->isSuccessful()) {
            throw new Failure(
                ExitCode::Ffmpeg,
                "{$command[0]} could not read the recording.",
                Tool::lastErrorLine($process),
            );
        }

        return $process;
    }
}
