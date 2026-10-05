<?php

declare(strict_types=1);

namespace LoomContext;

use InvalidArgumentException;

/**
 * Decides which moments of a recording are worth a screenshot. A fixed interval alone either misses the
 * half-second a toast is on screen or yields dozens of identical frames of a static page, so candidates are
 * the screens that settle, narrated moments, and the end; near-identical neighbours are then dropped and what
 * remains is capped by priority.
 */
class FrameSelector
{
    // Spoken words that usually mean "the screen right now matters".
    private const MomentWords = [
        'click', 'clicked', 'clicking', 'error', 'see', 'here', 'notice', 'look', 'shows', 'showing',
        'broken', 'wrong', 'bug', 'should', 'instead', 'loading', 'stuck', 'spinning', 'blank', 'missing',
    ];

    // A screen that holds for fewer samples than this is passing by, not a state.
    private const SettledSamples = 2;

    /**
     * The first sample of each run of near-identical samples long enough to count as a settled screen.
     *
     * @param  list<array{ts: float, dhash: string, mean: int}>  $samples
     * @return list<Frame>
     */
    public static function states(array $samples): array
    {
        $runs = [];

        foreach ($samples as $sample) {
            $frame = new Frame(
                (float) $sample['ts'],
                FrameReason::State,
                dhash: $sample['dhash'],
                mean: (int) $sample['mean'],
            );

            $current = array_key_last($runs);

            if ($current !== null && self::isDuplicate($frame, $runs[$current][0])) {
                $runs[$current][] = $frame;

                continue;
            }

            $runs[] = [$frame];
        }

        $settled = array_filter($runs, fn (array $run) => count($run) >= self::SettledSamples);

        return array_values(array_map(fn (array $run) => $run[0], $settled));
    }

    /**
     * Just before the reported duration: a seek to the duration itself decodes nothing.
     */
    public static function lastFrameAt(float $duration): float
    {
        return max(0.0, $duration - 0.1);
    }

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
     * Settled screens, narrated moments and the end, in time order and held inside the recording. Close
     * neighbours all stay, for dedupe() to judge by their images; only frames at the very same moment, which
     * would be the same image, collapse to the highest priority.
     *
     * @param  list<Frame>  $states
     * @param  list<float>  $moments
     * @return list<Frame>
     */
    public static function candidates(float $duration, array $states, array $moments): array
    {
        $end = self::lastFrameAt($duration);

        $frames = [
            ...array_map(fn (Frame $state) => new Frame(min($state->seconds, $end), FrameReason::State), $states),
            ...array_map(fn (float $moment) => new Frame(min($moment, $end), FrameReason::Say), $moments),
            new Frame($end, FrameReason::End),
        ];

        usort($frames, self::chronologically(...));

        $distinct = [];

        foreach ($frames as $frame) {
            $previous = end($distinct);

            if ($previous === false || $frame->seconds !== $previous->seconds) {
                $distinct[] = $frame;
            }
        }

        return $distinct;
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
     * Drops a frame that repeats the last kept one. A narrated moment takes the place of the repeat it follows,
     * unless that is the end frame, which always stays. Once $keepGap seconds have passed a repeat is kept
     * anyway, so a long static stretch still gets an occasional frame.
     *
     * @param  list<Frame>  $frames  in time order
     * @return list<Frame>
     */
    public static function dedupe(array $frames, float $keepGap = 12.0): array
    {
        $kept = [];

        foreach ($frames as $frame) {
            $last = end($kept);

            if ($last === false || $frame->reason === FrameReason::End || ! self::repeats($frame, $last, $keepGap)) {
                $kept[] = $frame;

                continue;
            }

            if ($frame->reason === FrameReason::Say && $last->reason !== FrameReason::End) {
                $kept[array_key_last($kept)] = $frame;
            }
        }

        return $kept;
    }

    private static function repeats(Frame $frame, Frame $last, float $keepGap): bool
    {
        return $frame->seconds - $last->seconds < $keepGap && self::isDuplicate($frame, $last);
    }

    /**
     * Trims to $maxFrames, in time order. The end frame always stays; the rest of the budget goes by priority
     * (see FrameReason), and the first priority that does not fit is sampled evenly across the recording.
     *
     * @param  list<Frame>  $frames
     * @return list<Frame>
     */
    public static function cap(array $frames, int $maxFrames): array
    {
        if ($maxFrames <= 0) {
            return [];
        }

        if (count($frames) <= $maxFrames) {
            usort($frames, self::chronologically(...));

            return $frames;
        }

        $selected = array_values(array_filter($frames, fn (Frame $frame) => $frame->reason === FrameReason::End));
        $remaining = max(0, $maxFrames - count($selected));
        $groups = [];

        foreach ($frames as $frame) {
            if ($frame->reason !== FrameReason::End) {
                $groups[$frame->reason->priority()][] = $frame;
            }
        }

        ksort($groups);

        foreach ($groups as $group) {
            usort($group, fn (Frame $a, Frame $b) => $a->seconds <=> $b->seconds);

            if (count($group) <= $remaining) {
                array_push($selected, ...$group);
                $remaining -= count($group);

                continue;
            }

            array_push($selected, ...self::sampleEvenly($group, $remaining));

            break;
        }

        usort($selected, self::chronologically(...));

        return $selected;
    }

    /**
     * Time order; of two frames at the same moment, the higher priority comes first.
     */
    private static function chronologically(Frame $a, Frame $b): int
    {
        return [$a->seconds, $a->reason->priority()] <=> [$b->seconds, $b->reason->priority()];
    }

    /**
     * @param  list<Frame>  $frames
     * @return list<Frame>
     */
    private static function sampleEvenly(array $frames, int $slots): array
    {
        if ($slots <= 0 || $frames === []) {
            return [];
        }

        if ($slots === 1) {
            return [$frames[(int) round((count($frames) - 1) / 2)]];
        }

        $last = count($frames) - 1;
        $selected = [];

        for ($slot = 0; $slot < $slots; $slot++) {
            $index = (int) round($slot * $last / ($slots - 1));
            $selected[] = $frames[$index];
        }

        return $selected;
    }
}
