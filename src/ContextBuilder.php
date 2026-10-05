<?php

declare(strict_types=1);

namespace LoomContext;

class ContextBuilder
{
    private const NoTranscriptNote =
        'Loom had no transcript for this video (still processing, or disabled). Frames only.';

    /**
     * Builds CONTEXT.md and manifest.json for a Loom link, or for a bundle directory that is already on disk.
     * A partial bundle is still built: a transcript without frames, or frames without a transcript, is worth
     * summarising.
     *
     * @param  array<string, string|bool|null>  $options
     * @return array<string, mixed>
     */
    public static function build(string $target, array $options): array
    {
        $notes = [];

        $bundle = $target;

        if (! is_dir($target)) {
            $fetched = (new Fetcher)->fetch(
                $target,
                (string) $options['out'],
                (string) $options['cookies-from-browser'],
                $options['password'],
                (bool) $options['refresh'],
            );

            $bundle = $fetched['dir'];

            $notes = self::notesFor($fetched);
        }

        if (is_file("{$bundle}/video.mp4") && ($options['refresh'] || ! FrameExtractor::hasRun($bundle))) {
            FrameExtractor::run($bundle, (int) $options['max-frames']);
        }

        return self::write($bundle, $notes);
    }

    /**
     * Writes CONTEXT.md and manifest.json from what the bundle holds now; nothing is fetched or extracted.
     *
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    public static function write(string $bundle, array $notes = []): array
    {
        // Entities come from the narration; the timeline prefers the captions, whose cue times are more exact.
        $phrases = Transcript::load($bundle);

        $timelinePhrases = Transcript::captions($bundle);

        if ($phrases === [] && $timelinePhrases === [] && is_file("{$bundle}/video.mp4")) {
            $notes = self::notesWithoutTranscript("{$bundle}/video.mp4", $notes);
        }

        $extracted = self::readIfPresent("{$bundle}/frames.json");

        $frames = $extracted['frames'] ?? [];

        $chapters = self::readIfPresent("{$bundle}/chapters.json");

        $brief = is_file("{$bundle}/brief.md") ? (string) file_get_contents("{$bundle}/brief.md") : null;

        $meta = self::readIfPresent("{$bundle}/meta.json") ?: ['video_id' => basename($bundle)];

        $config = Entities::findConfig();

        $entities = Entities::spot($phrases, Entities::patterns($config));

        file_put_contents(
            "{$bundle}/CONTEXT.md",
            ContextDocument::render(
                $meta,
                $frames,
                $chapters,
                $entities,
                $brief,
                Timeline::align($timelinePhrases, $frames),
                $notes,
                $extracted['pulls'] ?? [],
            ),
        );

        $manifest = [
            'video_id' => $meta['video_id'] ?? null,
            'dir' => $bundle,
            'context' => "{$bundle}/CONTEXT.md",
            'phrases' => count($phrases),
            'frames' => count($frames),
            'chapters' => count($chapters),
            'entities' => count($entities),
            'entities_config' => $config,
            'brief' => $brief !== null,
            'approx_frame_tokens' => count($frames) * 1500,
            'notes' => $notes,
        ];

        Json::write("{$bundle}/manifest.json", $manifest);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $fetched
     * @return list<string>
     */
    private static function notesFor(array $fetched): array
    {
        $notes = [];

        if ($fetched['transcript'] === 'none') {
            $notes[] = self::NoTranscriptNote;
        }

        if (! $fetched['mp4']) {
            $notes[] = 'The MP4 could not be downloaded (download disabled by the owner?). Transcript only.';
        }

        return $notes;
    }

    /**
     * With no transcript at all, a recording without sound is told apart from one Loom has not transcribed.
     *
     * @param  list<string>  $notes
     * @return list<string>
     */
    private static function notesWithoutTranscript(string $video, array $notes): array
    {
        if (! Ffmpeg::hasAudio($video)) {
            return [
                ...array_filter($notes, fn (string $note) => $note !== self::NoTranscriptNote),
                'This video has no audio track and no transcript is available.',
            ];
        }

        if ($notes === []) {
            return [self::NoTranscriptNote];
        }

        return $notes;
    }

    /**
     * @return array<mixed>
     */
    private static function readIfPresent(string $path): array
    {
        return is_file($path) ? Json::read($path) : [];
    }
}
