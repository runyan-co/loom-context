<?php

declare(strict_types=1);

namespace LoomContext;

use InvalidArgumentException;

/**
 * Decides which moments of a recording are worth a screenshot. A fixed interval alone either misses the
 * half-second a toast is on screen or yields dozens of identical frames of a static page, so candidates are
 * the union of a fixed tick, scene cuts, and narrated moments; near-identical neighbours are then dropped
 * and what remains is capped by priority.
 */
class FrameSelector
{
    // Spoken words that usually mean "the screen right now matters".
    private const MomentWords = [
        'click', 'clicked', 'clicking', 'error', 'see', 'here', 'notice', 'look', 'shows', 'showing',
        'broken', 'wrong', 'bug', 'should', 'instead', 'loading', 'stuck', 'spinning', 'blank', 'missing',
    ];

    public static function isMoment(string $text): bool
    {
        preg_match_all("/[a-z']+/", strtolower($text), $words);

        return array_intersect($words[0], self::MomentWords) !== [];
    }

    /**
     * The screen one second after a "click / see / error" phrase starts is usually the one worth keeping.
     *
     * @param  list<Phrase>  $phrases
     * @return list<float>
     */
    public static function moments(array $phrases, float $lead = 1.0): array
    {
        $narrated = array_filter($phrases, fn (Phrase $phrase) => self::isMoment($phrase->text));

        return array_values(array_map(fn (Phrase $phrase) => $phrase->seconds + $lead, $narrated));
    }

    /**
     * Ticks, cuts and moments in time order; neighbours closer than $minGap collapse to the higher-priority one.
     *
     * @param  list<float>  $cuts
     * @param  list<float>  $moments
     * @return list<Frame>
     */
    public static function candidates(
        float $duration,
        float $interval,
        array $cuts,
        array $moments,
        float $minGap = 1.5,
    ): array {
        // The last frame sits just before the reported duration; a seek to the duration itself decodes nothing.
        $end = max(0.0, $duration - 0.1);

        $raw = [];

        for ($tick = 0.0; $tick <= $duration; $tick += $interval) {
            $raw[] = new Frame(min(round($tick, 3), $end), FrameReason::Tick);
        }

        foreach ($cuts as $cut) {
            // 0.2 s after a cut: past any fade
            $raw[] = new Frame(min($cut + 0.2, $end), FrameReason::Cut);
        }

        foreach ($moments as $moment) {
            $raw[] = new Frame(min($moment, $end), FrameReason::Say);
        }

        $raw = array_filter($raw, fn (Frame $frame) => $frame->seconds >= 0);

        usort($raw, fn (Frame $a, Frame $b) => [$a->seconds, $a->reason->priority()] <=> [
            $b->seconds,
            $b->reason->priority(),
        ]);

        $merged = [];

        foreach ($raw as $candidate) {
            $previous = end($merged);

            if ($previous === false || $candidate->seconds - $previous->seconds >= $minGap) {
                $merged[] = $candidate;

                continue;
            }

            if ($candidate->reason->priority() < $previous->reason->priority()) {
                $merged[array_key_last($merged)] = $candidate;
            }
        }

        return $merged;
    }

    /**
     * A 64-bit difference hash (as hex) and the mean luminance of a 9x8 grayscale thumbnail.
     *
     * @return array{0: string, 1: int}
     */
    public static function signature(string $gray): array
    {
        if (strlen($gray) !== 72) {
            throw new InvalidArgumentException(sprintf('expected 72 gray bytes, got %d', strlen($gray)));
        }

        $pixels = array_values(unpack('C*', $gray));

        $hash = '';

        foreach (array_chunk($pixels, 9) as $row) {
            $bits = 0;

            for ($column = 0; $column < 8; $column++) {
                $bits = ($bits << 1) | ($row[$column] < $row[$column + 1] ? 1 : 0);
            }

            $hash .= sprintf('%02x', $bits);
        }

        return [$hash, intdiv(array_sum($pixels), 72)];
    }

    /**
     * The mean guard matters: a flat white screen and a flat navy one have no gradient, so both hash to zero.
     */
    public static function isDuplicate(Frame $a, Frame $b, int $maxHamming = 6, int $maxMeanDelta = 8): bool
    {
        $differingBits = 0;

        foreach (str_split($a->dhash, 2) as $index => $byte) {
            $differingBits += substr_count(decbin(hexdec($byte) ^ hexdec(substr($b->dhash, $index * 2, 2))), '1');
        }

        return $differingBits <= $maxHamming && abs($a->mean - $b->mean) <= $maxMeanDelta;
    }

    /**
     * Drops a frame that repeats the last kept one, unless it is a narrated moment or the gap has grown past
     * $keepGapFactor ticks (a long static stretch still gets an occasional frame).
     *
     * @param  list<Frame>  $frames
     * @return list<Frame>
     */
    public static function dedupe(array $frames, float $interval, float $keepGapFactor = 3.0): array
    {
        $kept = [];

        foreach ($frames as $frame) {
            $last = end($kept);

            $repeatsLast = $last !== false
                && $frame->reason !== FrameReason::Say
                && $frame->seconds - $last->seconds < $interval * $keepGapFactor
                && self::isDuplicate($frame, $last);

            if (! $repeatsLast) {
                $kept[] = $frame;
            }
        }

        return $kept;
    }

    /**
     * Trims to $maxFrames by dropping ticks first, then cuts, then narrated moments, keeping time order.
     *
     * @param  list<Frame>  $frames
     * @return list<Frame>
     */
    public static function cap(array $frames, int $maxFrames): array
    {
        if (count($frames) <= $maxFrames) {
            return $frames;
        }

        $ranked = $frames;

        usort($ranked, fn (Frame $a, Frame $b) => [$a->reason->priority(), $a->seconds] <=> [
            $b->reason->priority(),
            $b->seconds,
        ]);

        $keep = array_map(spl_object_id(...), array_slice($ranked, 0, $maxFrames));

        return array_values(array_filter($frames, fn (Frame $frame) => in_array(spl_object_id($frame), $keep, true)));
    }
}
