<?php

declare(strict_types=1);

namespace LoomContext;

class Timeline
{
    /**
     * One row per phrase, pointing at the nearest frame within $window seconds or else the latest earlier
     * one, plus a row for every frame no phrase claimed.
     *
     * @param  list<Phrase>  $phrases
     * @param  list<array{ts: float, path: string, reason: string}>  $frames
     * @return list<array{ts: float, text: ?string, frame: ?string}>
     */
    public static function align(array $phrases, array $frames, float $window = 2.0): array
    {
        usort($frames, fn (array $a, array $b) => $a['ts'] <=> $b['ts']);

        $rows = [];

        $claimed = [];

        foreach ($phrases as $phrase) {
            $frame = self::nearest($frames, $phrase->seconds, $window) ?? self::latestBefore($frames, $phrase->seconds);

            $rows[] = ['ts' => $phrase->seconds, 'text' => $phrase->text, 'frame' => $frame['path'] ?? null];

            $claimed[] = $frame['path'] ?? null;
        }

        foreach ($frames as $frame) {
            if (! in_array($frame['path'], $claimed, true)) {
                $rows[] = ['ts' => (float) $frame['ts'], 'text' => null, 'frame' => $frame['path']];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['ts'], $a['text'] === null] <=> [$b['ts'], $b['text'] === null]);

        return $rows;
    }

    /**
     * @param  list<array{ts: float, path: string, reason: string}>  $frames
     * @return ?array{ts: float, path: string, reason: string}
     */
    private static function nearest(array $frames, float $seconds, float $window): ?array
    {
        $nearest = null;

        foreach ($frames as $frame) {
            $distance = abs($frame['ts'] - $seconds);

            if ($distance > $window) {
                continue;
            }

            if ($nearest === null || $distance < abs($nearest['ts'] - $seconds)) {
                $nearest = $frame;
            }
        }

        return $nearest;
    }

    /**
     * @param  list<array{ts: float, path: string, reason: string}>  $frames
     * @return ?array{ts: float, path: string, reason: string}
     */
    private static function latestBefore(array $frames, float $seconds): ?array
    {
        $earlier = array_filter($frames, fn (array $frame) => $frame['ts'] <= $seconds);

        return $earlier === [] ? null : end($earlier);
    }
}
