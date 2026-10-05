<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A throwaway project directory the scripts run in, with its own HOME and its own stand-in commands ahead of
 * PATH, so a run can neither reach the network nor pick up the developer's Loom config, cookies, or yt-dlp.
 */
class Workspace
{
    public const VideoId = '0123456789abcdef0123456789abcdef';

    public const ShareUrl = 'https://www.loom.com/share/'.self::VideoId;

    public const Bundle = '.loom/'.self::VideoId;

    public string $root;

    public string $home;

    public string $bin;

    public string $checkout;

    private ?FakeLoom $loom = null;

    private bool $isolated = false;

    private function __construct(private string $base)
    {
        $this->root = "{$base}/project";
        $this->home = "{$base}/home";
        $this->bin = "{$base}/bin";
        $this->checkout = "{$base}/skill";
    }

    public static function create(): self
    {
        $workspace = new self(sys_get_temp_dir().'/loom-context-'.bin2hex(random_bytes(6)));

        foreach ([$workspace->root, $workspace->home, $workspace->bin, "{$workspace->checkout}/scripts"] as $directory) {
            mkdir($directory, 0700, true);
        }

        $workspace->checkOutSkill();

        // Present from the start with no rules, so a test that forgets to fake yt-dlp fails instead of calling Loom.
        $workspace->fakeYtDlp();

        return $workspace;
    }

    /**
     * The entry file and shell helpers find their .env and vendor folder beside themselves, so tests run copies
     * of them from a checkout of their own: the developer's real .env is never read, and a test can write one.
     */
    private function checkOutSkill(): void
    {
        copy(self::skillPath('loom'), "{$this->checkout}/loom");

        foreach (glob(self::skillPath('scripts/*.sh')) as $script) {
            copy($script, sprintf('%s/scripts/%s', $this->checkout, basename($script)));
        }

        symlink(self::skillPath('vendor'), "{$this->checkout}/vendor");
    }

    public function dotenv(string $contents): void
    {
        file_put_contents("{$this->checkout}/.env", $contents);
    }

    public static function skillPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    public static function fixture(string $name): string
    {
        return dirname(__DIR__).'/fixtures/'.$name;
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    /**
     * @param  string|array<mixed>  $contents  an array is written as JSON
     */
    public function write(string $relative, string|array $contents): string
    {
        $path = $this->path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, is_array($contents) ? json_encode($contents) : $contents);

        return $path;
    }

    public function read(string $relative): string
    {
        return file_get_contents($this->path($relative));
    }

    public function bundlePath(string $file): string
    {
        return $this->path(sprintf('%s/%s', self::Bundle, $file));
    }

    public function readBundle(string $file): string
    {
        return file_get_contents($this->bundlePath($file));
    }

    /**
     * Lays out a fetched bundle by hand, for scripts that work on what is already on disk.
     *
     * @param  array<string, string|array<mixed>>  $files
     */
    public function bundle(array $files = []): string
    {
        if (! is_dir($this->path(self::Bundle))) {
            mkdir($this->path(self::Bundle), 0700, true);
        }

        foreach ($files as $name => $contents) {
            $this->write(sprintf('%s/%s', self::Bundle, $name), $contents);
        }

        return self::Bundle;
    }

    /**
     * Puts a recording into the bundle, as if the MP4 had been downloaded.
     *
     * @param  list<string>  $sources  lavfi sources the clip cuts between
     */
    public function video(array $sources, float $secondsEach = 7.5): void
    {
        copy(FixtureVideo::path($sources, $secondsEach), $this->bundlePath('video.mp4'));
    }

    /**
     * @param  string|array<mixed>  $contents  an array is written as JSON
     */
    public function writeHome(string $relative, string|array $contents): string
    {
        return $this->write('../home/'.$relative, $contents);
    }

    /**
     * Runs one of the skill's commands the way the agent does: `php loom <command> ...`.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function loom(string $command, array $arguments = [], array $environment = []): ScriptResult
    {
        return $this->php("{$this->checkout}/loom", [$command, ...$arguments], $environment);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function php(string $file, array $arguments = [], array $environment = []): ScriptResult
    {
        return $this->execute([PHP_BINARY, $file, ...$arguments], $environment);
    }

    /**
     * Runs one of the shell helpers in scripts/, or a copy of one when given an absolute path.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function script(
        string $name,
        array $arguments = [],
        array $environment = [],
        ?string $stdin = null,
    ): ScriptResult {
        $path = str_starts_with($name, '/') ? $name : "{$this->checkout}/scripts/{$name}";

        return $this->execute([(new ExecutableFinder)->find('bash'), $path, ...$arguments], $environment, $stdin);
    }

    /**
     * @param  array<string, string>  $environment
     */
    public function bash(string $command, array $environment = []): ScriptResult
    {
        return $this->execute([(new ExecutableFinder)->find('bash'), '-c', $command], $environment);
    }

    /**
     * Puts a stand-in for a real command first on PATH.
     */
    public function command(string $name, string $body): void
    {
        file_put_contents("{$this->bin}/{$name}", "#!/bin/sh\n{$body}\n");

        chmod("{$this->bin}/{$name}", 0755);
    }

    /**
     * A stand-in that does nothing but record that it was called, and with what.
     */
    public function spy(string $name): void
    {
        $this->command(
            $name,
            sprintf('printf \'%%s %%s\n\' %s "$*" >> %s', $name, escapeshellarg($this->commandLog())),
        );
    }

    /**
     * @return list<string>  each call to a spy, as "name arguments"
     */
    public function commandsRun(): array
    {
        return is_file($this->commandLog()) ? file($this->commandLog(), FILE_IGNORE_NEW_LINES) : [];
    }

    public function withoutCommand(string $name): void
    {
        unlink("{$this->bin}/{$name}");
    }

    /**
     * From here on PATH holds only this workspace's stand-ins and PHP itself, so a test decides exactly which
     * tools the machine has.
     */
    public function isolatePath(): void
    {
        $this->isolated = true;

        symlink(PHP_BINARY, "{$this->bin}/php");
    }

    /**
     * Each call to yt-dlp is answered by the first rule whose `when` arguments are all present and whose
     * `without` arguments are all absent. A rule may print `info` (as JSON) or `stdout`, write `stderr`, copy a
     * `video` to the path yt-dlp was told to download to, and choose the `exit` code (0 unless given).
     *
     * @param  array<string, mixed>  ...$rules
     */
    public function fakeYtDlp(array ...$rules): void
    {
        file_put_contents($this->base.'/yt-dlp-rules.json', json_encode($rules));

        $this->command(
            'yt-dlp',
            'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/fake-yt-dlp.php').' "$@"',
        );
    }

    /**
     * @return list<array{arguments: list<string>, cookie_jar: ?array{path: string, mode: string, contents: string}}>
     */
    public function ytDlpCalls(): array
    {
        $log = $this->base.'/yt-dlp-calls.jsonl';

        if (! is_file($log)) {
            return [];
        }

        return array_map(
            fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    /**
     * From here on the scripts' own requests to Loom go to a local fake instead of being refused.
     */
    public function fakeLoom(): FakeLoom
    {
        return $this->loom ??= FakeLoom::start($this->base.'/loom', dirname(self::fixture('any')));
    }

    public function destroy(): void
    {
        $this->loom?->stop();

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->base);
    }

    private function commandLog(): string
    {
        return "{$this->base}/commands.log";
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function execute(array $command, array $environment, ?string $stdin = null): ScriptResult
    {
        $process = new Process($command, $this->root, $this->environment($environment));

        $process->setTimeout(120);

        $process->setInput($stdin);

        $process->run();

        return new ScriptResult($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * Laid over the inherited environment; false removes a variable the developer may have set.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string|false>
     */
    private function environment(array $overrides): array
    {
        return [
            'LOOM_COOKIE' => false,
            'LOOM_OP_ITEM' => false,
            'LOOM_CONTEXT_CONFIG' => false,
            'HOME' => $this->home,
            'PATH' => $this->isolated ? $this->bin : $this->bin.PATH_SEPARATOR.getenv('PATH'),
            'NO_PROXY' => '127.0.0.1',
            // Nothing listens on port 9, so a request a test did not expect is refused at once.
            'LOOM_GRAPHQL_URL' => $this->loom?->url('/graphql') ?? 'http://127.0.0.1:9/graphql',
            'FAKE_YTDLP_RULES' => "{$this->base}/yt-dlp-rules.json",
            'FAKE_YTDLP_CALLS' => "{$this->base}/yt-dlp-calls.jsonl",
            ...$overrides,
        ];
    }
}
