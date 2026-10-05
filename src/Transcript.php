<?php

declare(strict_types=1);

namespace LoomContext;

/**
 * Loom serves a transcript two ways: phrase JSON ({"phrases": [{"ts": <seconds>, "value": <text>,
 * "speakerName": ...}]}) and WebVTT captions. Both carry timestamps, which is what lets frames be aligned
 * to narration.
 */
class Transcript
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<Phrase>
     */
    public static function fromJson(array $data): array
    {
        $phrases = [];

        foreach ($data['phrases'] ?? [] as $item) {
            $text = trim((string) ($item['value'] ?? $item['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $seconds = $item['ts'] ?? $item['start'] ?? $item['startTime'] ?? 0;

            $phrases[] = new Phrase((float) $seconds, $text, $item['speakerName'] ?? null);
        }

        usort($phrases, fn (Phrase $a, Phrase $b) => $a->seconds <=> $b->seconds);

        return $phrases;
    }

    /**
     * @return list<Phrase>
     */
    public static function fromVtt(string $captions): array
    {
        $phrases = [];

        $lines = preg_split('/\R/', $captions);

        for ($index = 0; $index < count($lines); $index++) {
            $start = self::cueStart($lines[$index]);

            if ($start === null) {
                continue;
            }

            $body = [];

            while (++$index < count($lines) && trim($lines[$index]) !== '') {
                $body[] = preg_replace('/<[^>]+>/', '', trim($lines[$index]));
            }

            $text = trim(implode(' ', $body));

            if ($text !== '') {
                $phrases[] = new Phrase($start, $text);
            }
        }

        return $phrases;
    }

    /**
     * Prefers the phrase JSON, falls back to captions, and is empty when the bundle has neither.
     *
     * @return list<Phrase>
     */
    public static function load(string $bundle): array
    {
        if (is_file("{$bundle}/transcript.json")) {
            return self::fromJson(Json::read("{$bundle}/transcript.json"));
        }

        if (is_file("{$bundle}/captions.vtt")) {
            return self::fromVtt((string) file_get_contents("{$bundle}/captions.vtt"));
        }

        return [];
    }

    /**
     * mm:ss; hours roll into the minutes because Looms are short.
     */
    public static function clock(float $seconds): string
    {
        $total = (int) round($seconds);

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    private static function cueStart(string $line): ?float
    {
        if (! str_contains($line, '-->')) {
            return null;
        }

        if (preg_match('/(?:(\d+):)?(\d{2}):(\d{2})[.,](\d{3})/', explode('-->', $line)[0], $time) !== 1) {
            return null;
        }

        return (int) $time[1] * 3600 + (int) $time[2] * 60 + (int) $time[3] + (int) $time[4] / 1000;
    }
}
