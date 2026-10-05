<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A local stand-in for www.loom.com (PHP's built-in server running fake-loom-router.php): it answers the
 * GraphQL endpoint with a canned response, serves fixture files the way Loom's CDN serves transcripts, and
 * records each GraphQL request so a test can check what a script actually sent.
 */
class FakeLoom
{
    private function __construct(
        private Process $server,
        private string $origin,
        private string $directory,
    ) {}

    public static function start(string $directory, string $filesFrom): self
    {
        mkdir($directory, 0700, true);

        $listener = stream_socket_server('tcp://127.0.0.1:0');

        $address = stream_socket_get_name($listener, false);

        fclose($listener);

        $server = new Process([PHP_BINARY, '-S', $address, __DIR__.'/fake-loom-router.php'], $directory, [
            'FAKE_LOOM_FILES' => $filesFrom,
            'FAKE_LOOM_REQUESTS' => "{$directory}/requests.jsonl",
            'FAKE_LOOM_RESPONSE' => "{$directory}/response.json",
        ]);

        $server->setTimeout(null);

        $loom = new self($server, "http://{$address}", $directory);

        $loom->respondWith(['data' => ['fetchVideoTranscript' => ['__typename' => 'InvalidRequestWarning']]]);

        $server->start();

        // The built-in server announces itself once it accepts connections.
        $server->waitUntil(fn (string $type, string $output) => str_contains($output, 'started'));

        if (! $server->isRunning()) {
            throw new RuntimeException("The fake Loom server did not start on {$address}");
        }

        return $loom;
    }

    public function url(string $path): string
    {
        return $this->origin.$path;
    }

    /**
     * Where the fake serves a fixture from, the way a signed CDN link points at a transcript.
     */
    public function fileUrl(string $fixture): string
    {
        return $this->url('/files/'.$fixture);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function respondWith(array $response): void
    {
        file_put_contents("{$this->directory}/response.json", json_encode($response));
    }

    /**
     * @return list<array{cookie: ?string, body: array<string, mixed>}>
     */
    public function requests(): array
    {
        $log = "{$this->directory}/requests.jsonl";

        if (! is_file($log)) {
            return [];
        }

        return array_map(
            fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    public function stop(): void
    {
        $this->server->stop(0);
    }
}
