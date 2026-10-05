<?php

declare(strict_types=1);

namespace LoomContext;

class FrameExtractor
{
    private const SampleRateHz = 2;

    /**
     * Whether automatic frames have been extracted; frames.json can also exist holding only pulled ones.
     */
    public static function hasRun(string $bundle): bool
    {
        $summary = "{$bundle}/frames.json";

        return is_file($summary) && array_key_exists('kept', Json::read($summary));
    }

    /**
     * Writes frames/*.jpg, frames/contact-sheet.jpg and frames.json for the bundle's video.mp4.
     *
     * @return array<string, mixed>
     */
    public static function run(
        string $bundle,
        int $maxFrames = 60,
        int $longEdge = Frame::MaxLongEdge,
        bool $dedupe = true,
    ): array {
        Ffmpeg::ensureInstalled();

        $video = "{$bundle}/video.mp4";

        if (! is_file($video)) {
            throw new Failure(ExitCode::BadInput, "no video.mp4 in {$bundle}; fetch the Loom first");
        }

        if (! is_dir("{$bundle}/frames")) {
            mkdir("{$bundle}/frames");
        }

        $duration = Ffmpeg::duration($video);

        $states = FrameSelector::states(Ffmpeg::sampledSignatures($video, self::SampleRateHz));

        $moments = FrameSelector::moments(Transcript::captions($bundle));

        $candidates = FrameSelector::candidates($duration, $states, $moments);

        $copies = [];

        foreach ($candidates as $frame) {
            $name = $frame->fileName();

            $copies[$name] = ($copies[$name] ?? 0) + 1;

            $frame->path = "frames/{$frame->fileName($copies[$name])}";

            Ffmpeg::extract($video, $frame->seconds, "{$bundle}/{$frame->path}", $longEdge);

            [$frame->dhash, $frame->mean] = FrameSelector::signature(Ffmpeg::grayThumbnail("{$bundle}/{$frame->path}"));

            $frame->bytes = filesize("{$bundle}/{$frame->path}");
        }

        $kept = FrameSelector::cap(
            $dedupe ? FrameSelector::dedupe($candidates) : $candidates,
            $maxFrames,
        );

        $keptPaths = array_map(fn (Frame $frame) => $frame->path, $kept);

        foreach ($candidates as $frame) {
            if (! in_array($frame->path, $keptPaths, true)) {
                unlink("{$bundle}/{$frame->path}");
            }
        }

        Ffmpeg::contactSheet(
            array_map(fn (string $path) => "{$bundle}/{$path}", $keptPaths),
            "{$bundle}/frames/contact-sheet.jpg",
            array_map(fn (Frame $frame) => $frame->seconds, $kept),
        );

        $previous = is_file("{$bundle}/frames.json") ? Json::read("{$bundle}/frames.json") : [];

        // Frames pulled on request outlive a new extraction, and do not count against its cap.
        $pulledFrames = array_filter(
            $previous['frames'] ?? [],
            fn (array $frame) => ($frame['reason'] ?? null) === FrameReason::Pull->value,
        );

        $summary = [
            'duration_s' => $duration,
            'sample_rate_hz' => self::SampleRateHz,
            'candidates' => count($candidates),
            'kept' => count($kept),
            'states' => count($states),
            'moments' => count($moments),
            'frames' => [...array_map(fn (Frame $frame) => $frame->toArray(), $kept), ...$pulledFrames],
            'contact_sheet' => 'frames/contact-sheet.jpg',
            'pulls' => $previous['pulls'] ?? [],
        ];

        Json::write("{$bundle}/frames.json", $summary);

        return $summary;
    }
}
