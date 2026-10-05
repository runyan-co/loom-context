<?php

declare(strict_types=1);

use Tests\Support\FixtureVideo;
use Tests\Support\Workspace;

it('turns a link into narration aligned to screenshots', function () {
    $transcript = ['url' => $this->workspace->fakeLoom()->fileUrl('transcript.json')];

    $this->workspace->fakeYtDlp(
        ['when' => ['--dump-single-json'], 'info' => loomInfo(['subtitles' => ['en' => [$transcript]]])],
        ['when' => ['-o'], 'video' => FixtureVideo::path(FixtureVideo::ScreenRecording, 7.5)],
    );

    $result = $this->workspace->loom('context', [Workspace::ShareUrl, '--cookies-from-browser', 'none']);

    expect($result->exitCode)->toBe(0)
        ->and($result->json()['phrases'])->toBe(5)
        ->and($result->json()['frames'])->toBeGreaterThan(0)
        ->and($result->json()['notes'])->toBe([])
        ->and($this->workspace->readBundle('CONTEXT.md'))->toContain(
            '# Invoice 500 — Loom context',
            '[00:04] "I click create invoice and you can see the 500 error right here." ▶ frames/f-0005-say.jpg',
            '- **ticket** `PROJ-1234` (first at 00:15)',
        )
        ->and(is_file($this->workspace->bundlePath('frames/f-0005-say.jpg')))->toBeTrue();
})->skip(! FixtureVideo::available(), 'ffmpeg is not installed');
