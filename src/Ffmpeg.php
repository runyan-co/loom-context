<?php

declare(strict_types=1);

namespace LoomContext;

use Symfony\Component\Process\Process;

class Ffmpeg
{
    // Where a label sits inside its tile, and how many times its 5x7 pixel font is enlarged to be read.
    private const LabelMargin = 4;

    private const LabelScale = 2;

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
     * @return array{0: int, 1: int}
     */
    public static function dimensions(string $image): array
    {
        $probe = self::run([
            'ffprobe',
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=width,height',
            '-of',
            'csv=s=x:p=0',
            $image,
        ]);

        [$width, $height] = array_map(intval(...), explode('x', trim($probe->getOutput())));

        return [$width, $height];
    }

    /**
     * The size a pulled frame of the video is saved at, before any crop or zoom.
     *
     * @return array{0: int, 1: int}
     */
    public static function frameSize(string $video): array
    {
        [$width, $height] = self::dimensions($video);

        return self::fitLongEdge($width, $height);
    }

    /**
     * Saves the frame at $seconds at its frame size, cropped to $region, then enlarged $zoom times or as far as
     * the long edge allows.
     *
     * @return array{0: int, 1: int} the saved image's width and height
     */
    public static function extractPull(
        string $video,
        float $seconds,
        string $destination,
        ?Region $region,
        int $zoom,
    ): array {
        [$frameWidth, $frameHeight] = self::frameSize($video);

        $filters = ["scale={$frameWidth}:{$frameHeight}"];

        if ($region !== null) {
            $filters[] = "crop={$region->width}:{$region->height}:{$region->x}:{$region->y}";
        }

        [$outputWidth, $outputHeight] = self::fitLongEdge(
            ($region?->width ?? $frameWidth) * $zoom,
            ($region?->height ?? $frameHeight) * $zoom,
        );

        $filters[] = "scale={$outputWidth}:{$outputHeight}";

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
            implode(',', $filters),
            '-q:v',
            '4',
            $destination,
        ]);

        return self::dimensions($destination);
    }

    /**
     * ffprobe lists no audio stream for a recording made without sound.
     */
    public static function hasAudio(string $video): bool
    {
        $probe = Tool::run([
            'ffprobe',
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'stream=index',
            '-of',
            'csv=p=0',
            $video,
        ]);

        return trim($probe->getOutput()) !== '';
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
     * The signature of the video sampled $fps times a second, in one pass: the 9x8 gray thumbnails stream to
     * stdout, and showinfo reports each one's timestamp on stderr.
     *
     * @return list<array{ts: float, dhash: string, mean: int}>
     */
    public static function sampledSignatures(string $video, float $fps = 2.0): array
    {
        $scan = Tool::run([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'info',
            '-i',
            $video,
            '-vf',
            "fps={$fps},scale=9:8:flags=area,format=gray,showinfo",
            '-f',
            'rawvideo',
            '-pix_fmt',
            'gray',
            '-',
        ]);

        preg_match_all('/pts_time:([0-9.]+)/', $scan->getErrorOutput(), $times);

        $samples = [];

        foreach (str_split($scan->getOutput(), 72) as $index => $gray) {
            if (strlen($gray) !== 72 || ! isset($times[1][$index])) {
                continue;
            }

            [$hash, $mean] = FrameSelector::signature($gray);

            $samples[] = ['ts' => (float) $times[1][$index], 'dhash' => $hash, 'mean' => $mean];
        }

        return $samples;
    }

    /**
     * Tiles the images, all one size, into a single sheet, each tile labelled with its timestamp.
     *
     * @param  list<string>  $images
     * @param  list<float>  $timestamps  one per image
     */
    public static function contactSheet(
        array $images,
        string $destination,
        array $timestamps = [],
        int $columns = 6,
        int $tileWidth = 320,
    ): void {
        if ($images === []) {
            return;
        }

        $rows = (int) ceil(count($images) / $columns);

        [$width, $height] = self::dimensions($images[0]);

        $tileHeight = self::even($tileWidth * $height / $width);

        $listing = sprintf('%s/contact-list.txt', dirname($destination));

        file_put_contents(
            $listing,
            implode('', array_map(fn (string $image) => sprintf("file '%s'\n", realpath($image)), $images)),
        );

        $labels = [];

        $labelInputs = [];

        foreach ($timestamps as $index => $timestamp) {
            $label = dirname($destination)."/.label-{$index}.pgm";

            file_put_contents($label, ContactSheetLabels::pgm(Transcript::clock($timestamp)));

            $labels[] = $label;

            array_push($labelInputs, '-i', $label);
        }

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
            ...$labelInputs,
            '-filter_complex',
            self::sheetFilter(count($labels), $columns, $rows, $tileWidth, $tileHeight),
            '-map',
            '[sheet]',
            '-frames:v',
            '1',
            '-q:v',
            '5',
            $destination,
        ]);

        foreach ([$listing, ...$labels] as $file) {
            unlink($file);
        }
    }

    /**
     * Scales and tiles input 0, then lays label input n, enlarged, over the top-left corner of tile n - 1, all
     * in one pass so the sheet is encoded once. The result is [sheet].
     */
    private static function sheetFilter(int $labels, int $columns, int $rows, int $tileWidth, int $tileHeight): string
    {
        $filters = ["[0:v]scale={$tileWidth}:{$tileHeight},tile={$columns}x{$rows}[tiles0]"];

        $scale = self::LabelScale;

        for ($index = 0; $index < $labels; $index++) {
            $x = ($index % $columns) * $tileWidth + self::LabelMargin;

            $y = intdiv($index, $columns) * $tileHeight + self::LabelMargin;

            $next = $index + 1;

            $filters[] = "[{$next}:v]scale=iw*{$scale}:ih*{$scale}:flags=neighbor[label{$index}]";

            $filters[] = "[tiles{$index}][label{$index}]overlay={$x}:{$y}[tiles{$next}]";
        }

        $filters[] = "[tiles{$labels}]null[sheet]";

        return implode(';', $filters);
    }

    /**
     * Scales a size down, never up, until its long edge fits; both sides stay even, as the encoder needs.
     *
     * @return array{0: int, 1: int}
     */
    private static function fitLongEdge(int $width, int $height): array
    {
        $scale = min(1.0, Frame::MaxLongEdge / max($width, $height));

        return [self::even($width * $scale), self::even($height * $scale)];
    }

    private static function even(float $pixels): int
    {
        return max(2, ((int) floor($pixels)) & ~1);
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
