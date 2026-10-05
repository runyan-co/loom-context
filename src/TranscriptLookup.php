<?php

declare(strict_types=1);

namespace LoomContext;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * yt-dlp's own FetchVideoTranscript query (through 2026.08.19 and upstream master) still selects
 * VideoTranscriptDetails.id, a field Loom removed: Loom answers HTTP 400 and yt-dlp comes back without
 * subtitles and without saying why. This sends the same query minus that field.
 */
class TranscriptLookup
{
    private const Query = <<<'GRAPHQL'
        query FetchVideoTranscript($videoId: ID!, $password: String) {
          fetchVideoTranscript(videoId: $videoId, password: $password) {
            ... on VideoTranscriptDetails { video_id source_url captions_source_url __typename }
            ... on GenericError { message __typename }
            __typename
          }
        }
        GRAPHQL;

    /**
     * Links to the phrase JSON and the captions, or nothing when Loom has none or cannot be asked: a missing
     * transcript never fails a run.
     *
     * @return list<string>
     */
    public static function links(
        HttpClientInterface $http,
        string $videoId,
        ?string $password,
        ?string $connectSid,
    ): array {
        // The test suite points this at a local fake.
        $endpoint = getenv('LOOM_GRAPHQL_URL') ?: 'https://www.loom.com/graphql';

        $headers = ['Origin' => 'https://www.loom.com'];

        if ($connectSid !== null) {
            $headers['Cookie'] = sprintf('connect.sid=%s', self::sessionValue($connectSid));
        }

        $body = [
            'operationName' => 'FetchVideoTranscript',
            'variables' => ['videoId' => $videoId, 'password' => $password],
            'query' => self::Query,
        ];

        try {
            $response = $http->request('POST', $endpoint, ['json' => $body, 'headers' => $headers])->toArray();
        } catch (ExceptionInterface $exception) {
            Log::line("transcript lookup failed: {$exception->getMessage()}");

            return [];
        }

        $details = $response['data']['fetchVideoTranscript'] ?? [];

        return array_values(array_filter([
            $details['source_url'] ?? null,
            $details['captions_source_url'] ?? null,
        ]));
    }

    /**
     * LOOM_COOKIE may hold the bare value or the whole "connect.sid=..." pair.
     */
    public static function sessionValue(string $connectSid): string
    {
        $parts = explode('connect.sid=', $connectSid, 2);

        return trim(end($parts));
    }
}
