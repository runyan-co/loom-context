<?php

declare(strict_types=1);

namespace LoomContext;

class VideoId
{
    /**
     * Takes share and embed links (with or without a scheme), bare ids, and Slack's '<url|label>' wrapping.
     */
    public static function parse(string $pasted): string
    {
        $text = explode('|', trim(trim($pasted), '<>'))[0];

        $isBareId = preg_match('/^[0-9a-f]{32}$/', $text) === 1;

        if (! $isBareId && ! str_contains($text, 'loom.com')) {
            throw self::notLoom($text);
        }

        if (preg_match('/[0-9a-f]{32}/', $text, $id) !== 1) {
            throw self::notLoom($text);
        }

        return $id[0];
    }

    public static function shareUrl(string $videoId): string
    {
        return "https://www.loom.com/share/{$videoId}";
    }

    private static function notLoom(string $text): Failure
    {
        return new Failure(
            ExitCode::BadInput,
            "not a Loom video URL or id: '{$text}' (expected https://www.loom.com/share/<32 hex>)",
        );
    }
}
