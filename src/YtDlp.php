<?php

declare(strict_types=1);

namespace LoomContext;

use Symfony\Component\Process\Process;

/**
 * yt-dlp is the fetch engine: it already speaks Loom's private GraphQL, honours browser cookies and video
 * passwords, and upstream absorbs Loom's API churn.
 */
class YtDlp
{
    public function __construct(private string $binary) {}

    public static function locate(): self
    {
        $binary = Tool::find('yt-dlp');

        if ($binary === null) {
            throw new Failure(
                ExitCode::YtDlp,
                'yt-dlp is not installed. Install it with `brew install yt-dlp` or `pip install yt-dlp`.',
            );
        }

        return new self($binary);
    }

    public function metadata(string $url, Access $access, ?string $password): Process
    {
        // --write-subs: without it yt-dlp skips the transcript lookup altogether.
        return Tool::run([
            ...$this->identifiedAs($access, $password),
            '--skip-download',
            '--dump-single-json',
            '--write-subs',
            $url,
        ]);
    }

    public function download(string $url, string $directory, Access $access, ?string $password): Process
    {
        return Tool::run([
            ...$this->identifiedAs($access, $password),
            '-f',
            'mp4/bestvideo*+bestaudio/best',
            '--merge-output-format',
            'mp4',
            '-o',
            "{$directory}/video.%(ext)s",
            $url,
        ]);
    }

    /**
     * A cookie reaches yt-dlp as a jar file or a browser name, never as an argument value.
     *
     * @return list<string>
     */
    private function identifiedAs(Access $access, ?string $password): array
    {
        $command = [$this->binary, '--no-playlist', '--no-warnings', '--no-progress'];

        if ($access->browser !== null) {
            array_push($command, '--cookies-from-browser', $access->browser);
        }

        if ($access->cookieJar !== null) {
            array_push($command, '--cookies', $access->cookieJar);
        }

        if ($password !== null) {
            array_push($command, '--video-password', $password);
        }

        return $command;
    }
}
