<?php

declare(strict_types=1);

namespace LoomContext;

use Generator;
use JsonException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns a pasted Loom link into a bundle directory: metadata, chapters, transcript, MP4. Owns the auth
 * ladder, the output layout, and the mapping from what yt-dlp says to an exit code and a fix.
 */
class Fetcher
{
    public const Hosts = 'www.loom.com, cdn.loom.com and luna.loom.com';

    private const Browsers = ['chrome', 'brave', 'edge', 'chromium', 'firefox', 'safari'];

    private ?YtDlp $ytDlp = null;

    private ?HttpClientInterface $http = null;

    /**
     * @return array<string, mixed>
     */
    public function fetch(
        string $pasted,
        string $outRoot,
        string $browsers = 'auto',
        ?string $password = null,
        bool $refresh = false,
        bool $skipVideo = false,
    ): array {
        $videoId = VideoId::parse($pasted);

        $url = VideoId::shareUrl($videoId);

        $bundle = self::prepareBundle($outRoot, $videoId);

        $authDirectory = sprintf('%s/loom-auth-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));

        mkdir($authDirectory, 0700);

        try {
            $access = $refresh || ! is_file("{$bundle}/info.json")
                ? $this->fetchMetadata($url, $videoId, $bundle, $authDirectory, $browsers, $password)
                : new Access('cached');

            if (! $skipVideo && ($refresh || ! is_file("{$bundle}/video.mp4"))) {
                $this->downloadVideo($url, $bundle, $access, $password);
            }
        } finally {
            array_map(unlink(...), glob("{$authDirectory}/*"));

            rmdir($authDirectory);
        }

        $meta = Json::read("{$bundle}/meta.json");

        return [
            'video_id' => $videoId,
            'dir' => $bundle,
            'title' => $meta['title'],
            'duration_s' => $meta['duration_s'],
            'transcript' => self::transcriptKind($bundle),
            'chapters' => Json::read("{$bundle}/chapters.json") !== [],
            'mp4' => is_file("{$bundle}/video.mp4"),
            'source' => 'yt-dlp',
        ];
    }

    /**
     * Recordings show real user data, so the output root ignores itself in git.
     */
    public static function prepareBundle(string $outRoot, string $videoId): string
    {
        $bundle = "{$outRoot}/{$videoId}";

        if (! is_dir($bundle)) {
            mkdir($bundle, 0777, true);
        }

        if (! file_exists("{$outRoot}/.gitignore")) {
            file_put_contents("{$outRoot}/.gitignore", "*\n");
        }

        return $bundle;
    }

    public static function transcriptKind(string $bundle): string
    {
        if (is_file("{$bundle}/transcript.json")) {
            return 'json';
        }

        return is_file("{$bundle}/captions.vtt") ? 'vtt' : 'none';
    }

    private function fetchMetadata(
        string $url,
        string $videoId,
        string $bundle,
        string $authDirectory,
        string $browsers,
        ?string $password,
    ): Access {
        [$info, $access] = $this->open($url, $authDirectory, $browsers, $password);

        self::saveMetadata($info, $bundle);

        $links = array_column(array_merge(...array_values($info['subtitles'] ?? [])), 'url');

        if ($links === []) {
            // The lookup cannot reuse a browser's cookies: a Loom opened that way needs LOOM_COOKIE for its transcript.
            $connectSid = $access->isAnonymous() ? null : (getenv('LOOM_COOKIE') ?: null);

            $links = TranscriptLookup::links($this->http(), $videoId, $password, $connectSid);
        }

        $this->saveTranscript($links, $bundle);

        return $access;
    }

    /**
     * Climbs the auth ladder, cheapest rung first, until yt-dlp returns the video's metadata.
     *
     * @return array{0: array<string, mixed>, 1: Access}
     */
    private function open(string $url, string $authDirectory, string $browsers, ?string $password): array
    {
        $detail = '';

        foreach (self::ladder($authDirectory, $browsers) as $access) {
            $attempt = $this->ytDlp()->metadata($url, $access, $password);

            if ($attempt->isSuccessful()) {
                Log::line("fetched metadata via {$access->label}");

                return [self::decodeInfo($attempt->getOutput()), $access];
            }

            $error = YtDlpError::fromStderr($attempt->getErrorOutput());

            $detail = Tool::lastErrorLine($attempt);

            self::stopUnlessWorthClimbing($error, $password, $detail);

            Log::line("{$access->label}: {$error->value}");
        }

        throw new Failure(
            ExitCode::Auth,
            "Could not open this Loom anonymously or with browser cookies: it is private, deleted, or the link is wrong. Fixes, in order: log into loom.com in Chrome (or Brave/Edge/Firefox/Safari); or give it Loom's session cookie (LOOM_COOKIE in the skill's .env, or `source scripts/loom_cookie.sh` for 1Password); or ask the recorder to set the link to 'anyone with the link'.",
            $detail,
        );
    }

    /**
     * @return Generator<Access>
     */
    private static function ladder(string $authDirectory, string $browsers): Generator
    {
        yield new Access('anonymous');

        if ($browsers !== 'none') {
            foreach ($browsers === 'auto' ? self::Browsers : [$browsers] as $browser) {
                yield new Access("browser:{$browser}", browser: $browser);
            }
        }

        $connectSid = (string) getenv('LOOM_COOKIE');

        if ($connectSid !== '') {
            yield new Access('env:LOOM_COOKIE', cookieJar: self::writeCookieJar($authDirectory, $connectSid));
        }
    }

    private static function stopUnlessWorthClimbing(YtDlpError $error, ?string $password, string $detail): void
    {
        if ($error === YtDlpError::Network) {
            throw new Failure(
                ExitCode::Network,
                sprintf(
                    "Cannot reach www.loom.com. Run this on a machine with open internet access, or allowlist %s in the sandbox's network policy.",
                    self::Hosts,
                ),
                $detail,
            );
        }

        if ($error === YtDlpError::Outdated) {
            throw new Failure(
                ExitCode::YtDlp,
                'yt-dlp could not talk to Loom. Run `pip install -U yt-dlp` (or `brew upgrade yt-dlp`) and retry; if it still fails Loom changed its API and upstream needs a fix.',
                $detail,
            );
        }

        if ($error !== YtDlpError::Password) {
            return;
        }

        throw new Failure(
            ExitCode::Auth,
            $password === null
                ? 'This Loom is password protected. Re-run with --password <value>.'
                : 'Loom rejected the video password.',
            $detail,
        );
    }

    /**
     * An owner-only Netscape jar holding nothing but Loom's session cookie; fetch() deletes it on the way out.
     */
    private static function writeCookieJar(string $authDirectory, string $connectSid): string
    {
        $jar = "{$authDirectory}/cookies.txt";

        touch($jar);

        chmod($jar, 0600);

        file_put_contents($jar, sprintf(
            "# Netscape HTTP Cookie File\n.loom.com\tTRUE\t/\tTRUE\t%d\tconnect.sid\t%s\n",
            time() + 7 * 24 * 3600,
            TranscriptLookup::sessionValue($connectSid),
        ));

        return $jar;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeInfo(string $stdout): array
    {
        try {
            return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Failure(
                ExitCode::YtDlp,
                'yt-dlp did not print the video metadata as JSON.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private static function saveMetadata(array $info, string $bundle): void
    {
        Json::write("{$bundle}/info.json", $info);

        Json::write("{$bundle}/chapters.json", $info['chapters'] ?? []);

        Json::write("{$bundle}/meta.json", [
            'video_id' => $info['id'] ?? null,
            'title' => $info['title'] ?? null,
            'uploader' => $info['uploader'] ?? null,
            'upload_date' => $info['upload_date'] ?? null,
            'duration_s' => $info['duration'] ?? null,
            'webpage_url' => $info['webpage_url'] ?? null,
            'width' => $info['width'] ?? null,
            'height' => $info['height'] ?? null,
            'source' => 'yt-dlp',
            'fetched_at' => date('Y-m-d\TH:i:sO'),
        ]);
    }

    /**
     * Loom's links do not say which is which, so each download is filed by what it contains.
     *
     * @param  list<string>  $links
     */
    private function saveTranscript(array $links, string $bundle): void
    {
        foreach ($links as $link) {
            try {
                $contents = $this->http()->request('GET', $link)->getContent();
            } catch (ExceptionInterface $exception) {
                Log::line("transcript download failed: {$exception->getMessage()}");

                continue;
            }

            $start = ltrim($contents);

            if (str_starts_with($start, '{')) {
                file_put_contents("{$bundle}/transcript.json", $contents);
            }

            if (str_starts_with($start, 'WEBVTT')) {
                file_put_contents("{$bundle}/captions.vtt", $contents);
            }
        }
    }

    private function downloadVideo(string $url, string $bundle, Access $access, ?string $password): void
    {
        $download = $this->ytDlp()->download($url, $bundle, $access, $password);

        if ($download->isSuccessful()) {
            return;
        }

        $error = YtDlpError::fromStderr($download->getErrorOutput());

        Log::line("video download failed ({$error->value}); continuing without frames");
    }

    /**
     * Located on first use, so a bad link or a cached bundle never needs yt-dlp installed.
     */
    private function ytDlp(): YtDlp
    {
        return $this->ytDlp ??= YtDlp::locate();
    }

    private function http(): HttpClientInterface
    {
        return $this->http ??= HttpClient::create([
            'timeout' => 30,
            'max_duration' => 120,
            'headers' => ['User-Agent' => 'Mozilla/5.0'],
        ]);
    }
}
