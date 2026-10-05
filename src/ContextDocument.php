<?php

declare(strict_types=1);

namespace LoomContext;

class ContextDocument
{
    /**
     * CONTEXT.md keeps stable headings, and leaves out a section it has nothing for, so an agent and a human
     * can both skim it.
     *
     * @param  array<string, mixed>  $meta
     * @param  list<array{ts: float, path: string, reason: string}>  $frames
     * @param  list<array{start_time?: float, title?: string}>  $chapters
     * @param  list<Entity>  $entities
     * @param  list<array{ts: float, text: ?string, frame: ?string}>  $timeline
     * @param  list<string>  $notes
     * @param  list<array<string, mixed>>  $pulls  as FramePuller records them in frames.json
     */
    public static function render(
        array $meta,
        array $frames,
        array $chapters,
        array $entities,
        ?string $brief,
        array $timeline,
        array $notes,
        array $pulls = [],
    ): string {
        $lines = [
            sprintf('# %s — Loom context', $meta['title'] ?? $meta['video_id'] ?? 'Untitled'),
            '',
            sprintf(
                'Source: %s · Recorded by %s on %s · %s · fetched via %s',
                $meta['webpage_url'] ?? 'unknown',
                $meta['uploader'] ?? 'unknown',
                self::uploadDate($meta['upload_date'] ?? null),
                Transcript::clock((float) ($meta['duration_s'] ?? 0)),
                $meta['source'] ?? 'unknown',
            ),
            ...array_map(fn (string $note) => "> {$note}", $notes),
            '',
        ];

        if ($brief !== null) {
            array_push($lines, '## AI brief (Loom)', '', trim($brief), '');
        }

        if ($chapters !== []) {
            array_push($lines, '## Chapters', '', ...array_map(self::chapterLine(...), $chapters));

            $lines[] = '';
        }

        if ($entities !== []) {
            array_push($lines, '## Entities spotted', '', ...array_map(self::entityLine(...), $entities));

            $lines[] = '';
        }

        array_push($lines, '## Timeline', '');

        if ($timeline === []) {
            $lines[] = '_No transcript and no frames were available._';
        }

        array_push($lines, ...array_map(self::timelineLine(...), $timeline));

        $lines[] = '';

        if ($frames !== []) {
            usort($frames, fn (array $a, array $b) => $a['ts'] <=> $b['ts']);

            array_push($lines, '## Frames', '', '| ts | file | reason |', '|---|---|---|');

            array_push($lines, ...array_map(self::frameLine(...), $frames));

            array_push($lines, '', 'Overview: frames/contact-sheet.jpg', '');
        }

        if ($pulls !== []) {
            array_push($lines, '## On-demand frames', '');

            foreach ($pulls as $pull) {
                $lines[] = self::pullLine($pull);

                array_push($lines, ...array_map(self::pulledFrameLine(...), $pull['frames'] ?? []));
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{start_time?: float, title?: string}  $chapter
     */
    private static function chapterLine(array $chapter): string
    {
        return sprintf('- [%s] %s', Transcript::clock((float) ($chapter['start_time'] ?? 0)), $chapter['title'] ?? '');
    }

    private static function entityLine(Entity $entity): string
    {
        return sprintf(
            '- **%s** `%s` (first at %s)',
            $entity->kind,
            $entity->value,
            Transcript::clock($entity->seconds),
        );
    }

    /**
     * @param  array{ts: float, text: ?string, frame: ?string}  $row
     */
    private static function timelineLine(array $row): string
    {
        $said = $row['text'] === null ? '' : sprintf(' "%s"', $row['text']);

        $shown = $row['frame'] === null ? '' : " ▶ {$row['frame']}";

        return sprintf('[%s]%s%s', Transcript::clock($row['ts']), $said, $shown);
    }

    /**
     * @param  array{ts: float, path: string, reason: string}  $frame
     */
    private static function frameLine(array $frame): string
    {
        return sprintf('| %s | %s | %s |', Transcript::clock((float) $frame['ts']), $frame['path'], $frame['reason']);
    }

    /**
     * @param  array<string, mixed>  $pull
     */
    private static function pullLine(array $pull): string
    {
        $cue = isset($pull['cue']) ? " — cue: {$pull['cue']}" : '';

        return sprintf('- Sheet: %s%s', $pull['sheet'] ?? '?', $cue);
    }

    /**
     * @param  array{ts: float, path: string, width: int, height: int, region: ?list<int>, zoom: int}  $frame
     */
    private static function pulledFrameLine(array $frame): string
    {
        $region = $frame['region'] === null ? 'full frame' : implode(',', $frame['region']);

        return sprintf(
            '  - [%s] %s (%d×%d, region %s, zoom %dx)',
            Transcript::clock((float) $frame['ts']),
            $frame['path'],
            $frame['width'],
            $frame['height'],
            $region,
            $frame['zoom'],
        );
    }

    /**
     * yt-dlp gives the upload date as YYYYMMDD; any other value is shown as it came.
     */
    private static function uploadDate(mixed $date): string
    {
        if (! is_string($date) || $date === '') {
            return '?';
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $parts) !== 1) {
            return $date;
        }

        [, $year, $month, $day] = $parts;

        return checkdate((int) $month, (int) $day, (int) $year) ? "{$year}-{$month}-{$day}" : $date;
    }
}
