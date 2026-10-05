<?php

declare(strict_types=1);

namespace LoomContext;

/**
 * Saves the frames an agent asks for, at a caption cue or across a window of time, optionally cropped and
 * zoomed, with a timestamped contact sheet. Each pull is recorded in frames.json and listed in CONTEXT.md.
 */
class FramePuller
{
    public const MaxFrames = 60;

    public const MaxZoom = 4;

    /**
     * @return array{frames: list<array<string, mixed>>, sheet: string}
     */
    public static function run(
        string $bundle,
        ?string $window,
        ?string $cue,
        float $every = 0.5,
        int $maxFrames = self::MaxFrames,
        bool $changedOnly = false,
        ?Region $region = null,
        int $zoom = 1,
    ): array {
        self::validate($window, $cue, $every, $maxFrames, $changedOnly, $zoom);

        Ffmpeg::ensureInstalled();

        $video = "{$bundle}/video.mp4";

        if (! is_file($video)) {
            throw new Failure(ExitCode::BadInput, "no video.mp4 in {$bundle}");
        }

        $duration = Ffmpeg::duration($video);

        if ($window !== null) {
            $times = self::windowTimes($window, $every, $maxFrames, $duration);
        } else {
            $times = [self::cueTime($bundle, $cue, $duration)];
        }

        if ($region !== null) {
            [$frameWidth, $frameHeight] = Ffmpeg::frameSize($video);

            if (! $region->fitsWithin($frameWidth, $frameHeight)) {
                throw new Failure(
                    ExitCode::BadInput,
                    "--region must fit within the {$frameWidth}x{$frameHeight} saved frame",
                );
            }
        }

        $pullDirectory = "{$bundle}/frames/pulls";

        if (! is_dir($pullDirectory)) {
            mkdir($pullDirectory, 0777, true);
        }

        $manifestPath = "{$bundle}/frames.json";

        $manifest = is_file($manifestPath) ? Json::read($manifestPath) : ['frames' => [], 'pulls' => []];

        $sequence = count($manifest['pulls'] ?? []) + 1;

        $records = [];

        $previous = null;

        foreach ($times as $index => $seconds) {
            $candidate = "{$pullDirectory}/.candidate-{$sequence}-{$index}.jpg";

            [$width, $height] = Ffmpeg::extractPull($video, $seconds, $candidate, $region, $zoom);

            if ($changedOnly) {
                [$dhash, $mean] = FrameSelector::signature(Ffmpeg::grayThumbnail($candidate));

                $frame = new Frame($seconds, FrameReason::Pull, dhash: $dhash, mean: $mean);

                if ($previous !== null && FrameSelector::isDuplicate($frame, $previous)) {
                    unlink($candidate);

                    continue;
                }

                $previous = $frame;
            }

            $clock = str_replace(':', '', Transcript::clock($seconds));

            $path = sprintf('frames/pulls/p-%s-%03d.jpg', $clock, $sequence * 100 + $index);

            rename($candidate, "{$bundle}/{$path}");

            $records[] = [
                'ts' => $seconds,
                'path' => $path,
                'width' => $width,
                'height' => $height,
                'reason' => FrameReason::Pull->value,
                'region' => $region?->toArray(),
                'zoom' => $zoom,
                'cue' => $cue,
            ];
        }

        $sheet = "frames/pulls/contact-sheet-{$sequence}.jpg";

        Ffmpeg::contactSheet(
            array_map(fn (array $record) => "{$bundle}/{$record['path']}", $records),
            "{$bundle}/{$sheet}",
            array_column($records, 'ts'),
        );

        foreach ($records as $record) {
            $manifest['frames'][] = [
                'ts' => $record['ts'],
                'path' => $record['path'],
                'reason' => $record['reason'],
                'width' => $record['width'],
                'height' => $record['height'],
            ];
        }

        $manifest['pulls'][] = [
            'sheet' => $sheet,
            'cue' => $cue,
            'window' => $window,
            'frames' => $records,
        ];

        Json::write($manifestPath, $manifest);

        // CONTEXT.md lists every pull, so it is rewritten from the bundle as it now stands.
        ContextBuilder::write($bundle);

        return ['frames' => $records, 'sheet' => $sheet];
    }

    private static function validate(
        ?string $window,
        ?string $cue,
        float $every,
        int $maxFrames,
        bool $changedOnly,
        int $zoom,
    ): void {
        if (($window === null) === ($cue === null)) {
            throw new Failure(ExitCode::BadInput, 'provide exactly one of --window or --cue');
        }

        if ($every <= 0) {
            throw new Failure(ExitCode::BadInput, '--every must be more than 0 seconds');
        }

        if ($maxFrames < 1 || $maxFrames > self::MaxFrames) {
            throw new Failure(ExitCode::BadInput, sprintf('--max-frames must be 1 to %d', self::MaxFrames));
        }

        if ($zoom < 1 || $zoom > self::MaxZoom) {
            throw new Failure(ExitCode::BadInput, sprintf('--zoom must be 1 to %d', self::MaxZoom));
        }

        if ($changedOnly && $window === null) {
            throw new Failure(ExitCode::BadInput, '--changed-only needs --window');
        }
    }

    /**
     * Every $every seconds from the window's start to its end, held inside the recording.
     *
     * @return list<float>
     */
    private static function windowTimes(string $window, float $every, int $maxFrames, float $duration): array
    {
        if (preg_match('/^(.+)-(.+)$/', $window, $bounds) !== 1) {
            throw new Failure(ExitCode::BadInput, 'window must be start-end');
        }

        $start = self::seconds($bounds[1]);

        $end = self::seconds($bounds[2]);

        if ($start < 0 || $end < $start || $start > $duration) {
            throw new Failure(ExitCode::BadInput, 'invalid frame window');
        }

        $lastFrame = FrameSelector::lastFrameAt($duration);

        $times = [];

        // The slack keeps float drift from dropping a sample that lands on the window's end.
        for ($seconds = $start; $seconds <= $end + 1e-6 && count($times) < $maxFrames; $seconds += $every) {
            $times[] = min($seconds, $lastFrame);

            // The rest of the window is past the recording, where every sample would repeat this one.
            if ($seconds >= $lastFrame) {
                break;
            }
        }

        return $times;
    }

    /**
     * One second after the earliest caption containing the cue, when the screen usually shows what was said.
     */
    private static function cueTime(string $bundle, string $cue, float $duration): float
    {
        $matches = array_filter(
            Transcript::captions($bundle),
            fn (Phrase $phrase) => stripos($phrase->text, $cue) !== false,
        );

        if ($matches === []) {
            throw new Failure(ExitCode::BadInput, "caption cue not found: {$cue}");
        }

        $earliest = min(array_map(fn (Phrase $phrase) => $phrase->seconds, $matches));

        return min($earliest + 1, FrameSelector::lastFrameAt($duration));
    }

    /**
     * Seconds from "12.5", "1:05" or "01:05.5".
     */
    private static function seconds(string $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(\d+):(\d{1,2}(?:\.\d+)?)$/', $value, $clock) === 1) {
            return (int) $clock[1] * 60 + (float) $clock[2];
        }

        throw new Failure(ExitCode::BadInput, "invalid timestamp: {$value}");
    }
}
