<?php

declare(strict_types=1);

use Tests\Support\Workspace;

it('shows each command with the line that runs it and a real example', function (array $arguments) {
    $result = $this->workspace->php("{$this->workspace->checkout}/loom", $arguments);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toBe(<<<'OVERVIEW'
            loom-context: turn a Loom recording into context an agent can read

              php loom context <loom> [options]
                  Build CONTEXT.md and manifest.json for a Loom, fetching it and extracting frames first when needed
                  e.g. php loom context https://www.loom.com/share/0123456789abcdef0123456789abcdef
                  e.g. php loom context .loom/0123456789abcdef0123456789abcdef --refresh --max-frames 30

              php loom fetch <loom> [options]
                  Fetch a Loom into a bundle directory: metadata, chapters, transcript, MP4
                  e.g. php loom fetch https://www.loom.com/share/0123456789abcdef0123456789abcdef --skip-video

              php loom frames <bundle> [options]
                  Extract a small, de-duplicated set of screenshots from a bundle, aligned to its narration
                  e.g. php loom frames .loom/0123456789abcdef0123456789abcdef --interval 2

            Add --help to a command for its options, e.g. php loom context --help

            OVERVIEW);
})->with([
    'no arguments' => [[]],
    '--help' => [['--help']],
    'help' => [['help']],
    'list' => [['list']],
]);

it('describes one command\'s own options and examples when asked for its help', function () {
    $result = $this->workspace->loom('context', ['--help']);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain(
            'context [options] [--] <loom>',
            'context https://www.loom.com/share/0123456789abcdef0123456789abcdef',
            '--interval=INTERVAL',
            '--cookies-from-browser=COOKIES-FROM-BROWSER',
            'A Loom link or id, or a bundle directory already on disk',
        );
});

it('exits 7 and says how to install its dependencies on a checkout that has no vendor directory', function () {
    $checkout = $this->workspace->path('fresh-checkout');

    mkdir($checkout, 0700);

    copy(Workspace::skillPath('loom'), "{$checkout}/loom");

    $result = $this->workspace->php("{$checkout}/loom", ['context', Workspace::ShareUrl]);

    expect($result->exitCode)->toBe(7)
        ->and($result->json()['error'])->toContain('composer install --no-dev', 'fresh-checkout');
});

it('treats a mistyped command as bad input and suggests the real one', function () {
    $result = $this->workspace->loom('contxt', [Workspace::ShareUrl]);

    expect($result->exitCode)->toBe(2)
        ->and($result->json()['error'])->toContain('"contxt" is not defined', 'context');
});

it('treats a mistyped option as bad input rather than guessing', function () {
    $result = $this->workspace->loom('context', [Workspace::ShareUrl, '--intervall', '2']);

    expect($result->exitCode)->toBe(2)
        ->and($result->json()['error'])->toContain('--intervall')
        ->and($this->workspace->ytDlpCalls())->toBe([]);
});

it('asks for the Loom when none is given', function () {
    $result = $this->workspace->loom('context');

    expect($result->exitCode)->toBe(2)
        ->and($result->json()['error'])->toContain('loom');
});
