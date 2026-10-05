<?php

declare(strict_types=1);

use Tests\Support\Workspace;

pest()
    ->beforeEach(function () {
        $this->workspace = Workspace::create();
    })
    ->afterEach(function () {
        $this->workspace->destroy();
    })
    ->in(__DIR__);

/**
 * The metadata yt-dlp prints for a Loom, cut down to the fields the scripts read.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function loomInfo(array $overrides = []): array
{
    return [
        'id' => Workspace::VideoId,
        'title' => 'Invoice 500',
        'uploader' => 'Jordan',
        'upload_date' => '20261001',
        'duration' => 30,
        'webpage_url' => Workspace::ShareUrl,
        'width' => 640,
        'height' => 360,
        'chapters' => [],
        'subtitles' => [],
        ...$overrides,
    ];
}

/**
 * The answer yt-dlp gives for a Loom that is private, deleted, or mistyped.
 *
 * @return array{stderr: string, exit: int}
 */
function findsNoFormats(): array
{
    return [
        'stderr' => 'ERROR: [loom] x: No video formats found!; please report this issue on  https://github.com/yt-dlp',
        'exit' => 1,
    ];
}

/**
 * Builds CONTEXT.md for a bundle holding exactly these files and returns its text.
 *
 * @param  array<string, string|array<mixed>>  $files
 * @param  array<string, string>  $environment
 */
function contextFor(Workspace $workspace, array $files, array $environment = []): string
{
    $workspace->loom('context', [$workspace->bundle($files)], $environment);

    return $workspace->readBundle('CONTEXT.md');
}

/**
 * @param  list<array{ts: float, value: string}>  $phrases
 * @return array{'transcript.json': array{phrases: list<array{ts: float, value: string}>}}
 */
function transcript(array ...$phrases): array
{
    return ['transcript.json' => ['phrases' => $phrases]];
}
