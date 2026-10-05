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
     */
    public static function render(
        array $meta,
        array $frames,
        array $chapters,
        array $entities,
        ?string $brief,
        array $timeline,
        array $notes,
    ): string {
        $lines = [
            sprintf('# %s — Loom context', $meta['title'] ?? $meta['video_id'] ?? 'Untitled'),
            '',
            sprintf(
                'Source: %s · Recorded by %s on %s · %s · fetched via %s',
                $meta['webpage_url'] ?? 'unknown',
                $meta['uploader'] ?? 'unknown',
                $meta['upload_date'] ?? '?',
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
}
