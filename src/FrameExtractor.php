<?php

declare(strict_types=1);

namespace LoomContext;

class FrameExtractor
{
    /**
     * Writes frames/*.jpg, frames/contact-sheet.jpg and frames.json for the bundle's video.mp4.
     *
     * @return array<string, mixed>
     */
    public static function run(
        string $bundle,
        float $interval = 4.0,
        float $scene = 0.25,
        int $maxFrames = 60,
        int $longEdge = 1568,
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

        $cuts = Ffmpeg::sceneCuts($video, $scene);

        $moments = FrameSelector::moments(Transcript::load($bundle));

        $candidates = FrameSelector::candidates($duration, $interval, $cuts, $moments);

        // A long recording gets a coarser tick rather than hundreds of extractions.
        while (count($candidates) > 4 * $maxFrames) {
            $interval *= 2;

            Log::line("many candidates; raising interval to {$interval}s");

            $candidates = FrameSelector::candidates($duration, $interval, $cuts, $moments);
        }

        foreach ($candidates as $frame) {
            $frame->path = "frames/{$frame->fileName()}";

            Ffmpeg::extract($video, $frame->seconds, "{$bundle}/{$frame->path}", $longEdge);

            [$frame->dhash, $frame->mean] = FrameSelector::signature(Ffmpeg::grayThumbnail("{$bundle}/{$frame->path}"));

            $frame->bytes = filesize("{$bundle}/{$frame->path}");
        }

        $kept = FrameSelector::cap(
            $dedupe ? FrameSelector::dedupe($candidates, $interval) : $candidates,
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
        );

        $summary = [
            'duration_s' => $duration,
            'interval_s' => $interval,
            'scene_threshold' => $scene,
            'candidates' => count($candidates),
            'kept' => count($kept),
            'cuts' => count($cuts),
            'moments' => count($moments),
            'frames' => array_map(fn (Frame $frame) => $frame->toArray(), $kept),
            'contact_sheet' => 'frames/contact-sheet.jpg',
        ];

        Json::write("{$bundle}/frames.json", $summary);

        return $summary;
    }
}
