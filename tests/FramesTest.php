<?php

declare(strict_types=1);

use Tests\Support\FixtureVideo;
use Tests\Support\Workspace;

/**
 * The frames kept for the bundle, each as "seconds reason".
 *
 * @return list<string>
 */
function framesKept(Workspace $workspace): array
{
    $frames = json_decode($workspace->readBundle('frames.json'), true)['frames'];

    return array_map(fn (array $frame) => sprintf('%.1f %s', $frame['ts'], $frame['reason']), $frames);
}

it('exits 6 and says how to install ffmpeg when it is missing', function () {
    $this->workspace->bundle();

    $result = $this->workspace->loom('frames', [Workspace::Bundle], ['PATH' => $this->workspace->bin]);

    expect($result->exitCode)->toBe(6)
        ->and($result->json()['error'])->toContain('ffmpeg/ffprobe not found', 'brew install ffmpeg');
});

describe('with ffmpeg', function () {
    it('exits 2 when the bundle has no MP4 to read', function () {
        $this->workspace->bundle();

        $result = $this->workspace->loom('frames', [Workspace::Bundle]);

        expect($result->exitCode)->toBe(2)
            ->and($result->json()['error'])->toContain('no video.mp4');
    });

    it('keeps settled screens, the narrated moment, and the end frame', function () {
        $this->workspace->bundle(transcript(['ts' => 10.0, 'value' => 'look at this error']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $result = $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '640']);

        $kept = framesKept($this->workspace);

        expect($result->json()['sample_rate_hz'])->toBe(2)
            ->and($result->json())->toHaveKey('states')
            ->and($kept)->toContain('11.0 say')
            ->and(count($kept))->toBeLessThanOrEqual(60)
            ->and(glob($this->workspace->bundlePath('frames/f-*.jpg')))->toHaveCount(count($kept))
            ->and(is_file($this->workspace->bundlePath('frames/contact-sheet.jpg')))->toBeTrue()
            ->and(is_file($this->workspace->bundlePath('frames/f-0011-say.jpg')))->toBeTrue();
    });

    it('lets a narrated moment take the place of the settled screen it repeats', function () {
        $this->workspace->bundle(transcript(['ts' => 11.0, 'value' => 'and then I click here']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320']);

        // The colour bars settle at 7.5 s; the moment at 12 s shows the same screen and takes its place.
        expect(framesKept($this->workspace))
            ->toContain('12.0 say')
            ->not->toContain('7.5 state');
    });

    it('ignores the deprecated interval option', function () {
        $this->workspace->bundle(transcript(['ts' => 25.0, 'value' => 'and then I click here']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320']);

        $byDefault = framesKept($this->workspace);

        $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320', '--interval', '0.5']);

        expect(framesKept($this->workspace))->toBe($byDefault);
    });

    it('holds a narrated moment in the last second on the end frame instead of seeking past it', function () {
        $this->workspace->bundle(transcript(['ts' => 29.5, 'value' => 'look at this error']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $result = $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320']);

        expect($result->exitCode, $result->stdout)->toBe(0)
            ->and(framesKept($this->workspace))->toContain('29.9 end');
    });

    it('saves narrated moments that round to the same second as separate files', function () {
        $this->workspace->bundle(transcript(
            ['ts' => 13.6, 'value' => 'click here'],
            ['ts' => 14.0, 'value' => 'see this'],
        ));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320']);

        // 14.6 s is on the colour bars and 15.0 s on navy; both are 00:15 on the clock.
        $frames = json_decode($this->workspace->readBundle('frames.json'), true)['frames'];

        $paths = array_column(array_filter($frames, fn (array $frame) => $frame['reason'] === 'say'), 'path');

        expect($paths)->toBe(['frames/f-0015-say.jpg', 'frames/f-0015-say-2.jpg'])
            ->and(glob($this->workspace->bundlePath('frames/f-*.jpg')))->toHaveCount(count($frames));
    });

    it('spends a tight frame budget on narrated moments and the end frame', function () {
        $this->workspace->bundle(transcript(['ts' => 10.0, 'value' => 'look at this error']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320', '--max-frames', '2']);

        expect(framesKept($this->workspace))->toContain('11.0 say', '29.9 end');
    });

    it('keeps compatibility interval options while bounding automatic output', function () {
        $this->workspace->bundle();

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $result = $this->workspace->loom(
            'frames',
            [Workspace::Bundle, '--long-edge', '320', '--interval', '0.5', '--max-frames', '3'],
        );

        expect($result->json()['sample_rate_hz'])->toBe(2)
            ->and($result->json()['candidates'])->toBeLessThanOrEqual(200)
            ->and(framesKept($this->workspace))->toHaveCount(3);
    });

    it('keeps a flat dark screen that follows a flat light one, though their gradients hash alike', function () {
        $this->workspace->bundle();

        $this->workspace->video(['color=c=white', 'color=c=navy'], secondsEach: 6);

        $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320']);

        $frames = json_decode($this->workspace->readBundle('frames.json'), true)['frames'];

        $dark = array_filter($frames, fn (array $frame) => $frame['mean'] < 60);

        expect($frames[0]['mean'])->toBeGreaterThan(200)
            ->and($dark)->not->toBeEmpty()
            ->and(reset($dark)['dhash'])->toBe($frames[0]['dhash']);
    });
})->skip(! FixtureVideo::available(), 'ffmpeg is not installed');
