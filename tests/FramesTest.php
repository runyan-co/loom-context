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

    it('keeps the cuts and the narrated moment, and drops repeats of a static screen', function () {
        $this->workspace->bundle(transcript(['ts' => 10.0, 'value' => 'look at this error']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $result = $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '640']);

        $kept = framesKept($this->workspace);

        expect($result->json()['cuts'])->toBe(3)
            // a cut is captured 0.2 s after it, the narrated moment one second after the phrase starts
            ->and($kept)->toContain('7.7 cut', '15.2 cut', '22.7 cut', '11.0 say')
            // 13 candidates; the ticks that repeat the white and navy screens are gone
            ->and(count($kept))->toBeLessThanOrEqual(12)
            ->and(glob($this->workspace->bundlePath('frames/f-*.jpg')))->toHaveCount(count($kept))
            ->and(is_file($this->workspace->bundlePath('frames/contact-sheet.jpg')))->toBeTrue()
            ->and(is_file($this->workspace->bundlePath('frames/f-0011-say.jpg')))->toBeTrue();
    });

    it('lets a narrated moment take the place of the tick it lands on', function () {
        $this->workspace->bundle(transcript(['ts' => 11.0, 'value' => 'and then I click here']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320']);

        expect(framesKept($this->workspace))
            ->toContain('12.0 say')
            ->not->toContain('12.0 tick');
    });

    it('spends a tight frame budget on narrated moments and cuts before ticks', function () {
        $this->workspace->bundle(transcript(['ts' => 10.0, 'value' => 'look at this error']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $this->workspace->loom('frames', [Workspace::Bundle, '--long-edge', '320', '--max-frames', '2']);

        expect(framesKept($this->workspace))->toBe(['7.7 cut', '11.0 say']);
    });

    it('coarsens the tick instead of extracting hundreds of frames from a long recording', function () {
        $this->workspace->bundle();

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $result = $this->workspace->loom(
            'frames',
            [Workspace::Bundle, '--long-edge', '320', '--interval', '0.5', '--max-frames', '3'],
        );

        expect($result->stderr)->toContain('many candidates; raising interval')
            ->and($result->json()['interval_s'])->toBeGreaterThan(0.5)
            ->and($result->json()['candidates'])->toBeLessThanOrEqual(12)
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
