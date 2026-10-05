<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\FixtureVideo;
use Tests\Support\Workspace;

/** @return array<string, mixed> */
function pullManifest(Workspace $workspace): array
{
    return json_decode($workspace->readBundle('frames.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The darkest and brightest luma in a rectangle of an image.
 *
 * @return array{0: int, 1: int}
 */
function lumaRange(string $image, int $x, int $y, int $width, int $height): array
{
    $ffmpeg = new Process([
        'ffmpeg',
        '-hide_banner',
        '-loglevel',
        'error',
        '-i',
        $image,
        '-vf',
        "crop={$width}:{$height}:{$x}:{$y},format=gray",
        '-f',
        'rawvideo',
        '-',
    ]);

    $pixels = unpack('C*', $ffmpeg->mustRun()->getOutput());

    return [min($pixels), max($pixels)];
}

describe('with ffmpeg', function () {
    it('pulls a timestamp window into a timestamped sheet and records each frame', function () {
        $this->workspace->bundle();

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $this->workspace->write(Workspace::Bundle.'/frames.json', [
            'frames' => [['ts' => 0.0, 'path' => 'frames/f-0000-tick.jpg', 'reason' => 'tick']],
            'pulls' => [],
        ]);

        $result = $this->workspace->loom(
            'frame',
            [Workspace::Bundle, '--window', '0-2', '--every', '1', '--max-frames', '4'],
        );

        $manifest = pullManifest($this->workspace);

        $pull = $result->json();

        expect($result->exitCode, $result->stdout.' '.$result->stderr)->toBe(0)
            ->and($pull['frames'])->toHaveCount(3)
            ->and($manifest['frames'])->toHaveCount(4)
            ->and($manifest['pulls'])->toHaveCount(1)
            ->and(is_file($this->workspace->bundlePath($manifest['pulls'][0]['sheet'])))->toBeTrue()
            ->and($manifest['pulls'][0]['frames'][0]['width'])->toBeGreaterThan(0)
            ->and($this->workspace->readBundle('CONTEXT.md'))->toContain(
                '## On-demand frames',
                $manifest['pulls'][0]['sheet'],
                $manifest['pulls'][0]['frames'][0]['path'],
            );
    });

    it('still extracts the automatic frames when a pull came first, keeping the pull', function () {
        $this->workspace->bundle(transcript(['ts' => 10.0, 'value' => 'look at this error']));

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $this->workspace->loom('frame', [Workspace::Bundle, '--window', '0-1']);

        $this->workspace->loom('context', [Workspace::Bundle]);

        $manifest = pullManifest($this->workspace);

        expect(array_unique(array_column($manifest['frames'], 'reason')))->toContain('pull', 'say', 'state', 'end')
            ->and($manifest['pulls'])->toHaveCount(1);
    });

    it('labels every tile legibly on a white screen, at the height the tiles really have', function () {
        $this->workspace->bundle();

        $this->workspace->video(['color=c=white']);

        // 4:3 crops make 320x240 tiles: the second row starts at y=240, where a 16:9 tile would put it at 180.
        $result = $this->workspace->loom(
            'frame',
            [Workspace::Bundle, '--window', '0-6', '--every', '1', '--region', '0,0,480,360'],
        );

        $sheet = $this->workspace->bundlePath($result->json()['sheet']);

        [$firstRowDarkest, $firstRowBrightest] = lumaRange($sheet, 4, 4, 60, 20);
        [$secondRowDarkest] = lumaRange($sheet, 4, 244, 60, 20);
        [$whereSixteenByNineWouldLabel] = lumaRange($sheet, 4, 184, 60, 20);

        expect($firstRowDarkest)->toBeLessThan(64)
            ->and($firstRowBrightest)->toBeGreaterThan(192)
            ->and($secondRowDarkest)->toBeLessThan(64)
            ->and($whereSixteenByNineWouldLabel)->toBeGreaterThan(192);
    });

    it('stops a window at the last frame of the recording', function () {
        $this->workspace->bundle();

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $result = $this->workspace->loom('frame', [Workspace::Bundle, '--window', '25-40', '--every', '1']);

        expect(array_column($result->json()['frames'], 'ts'))->toBe([25.0, 26.0, 27.0, 28.0, 29.0, 29.9]);
    });

    it('aims a pull at a matching caption cue', function () {
        $this->workspace->bundle([
            'captions.vtt' => "WEBVTT\n\n00:00:02.000 --> 00:00:04.000\nsave confirmation\n",
        ]);

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $result = $this->workspace->loom('frame', [Workspace::Bundle, '--cue', 'confirmation']);

        expect($result->exitCode)->toBe(0)
            ->and($result->json()['frames'][0]['ts'])->toBe(3.0);
    });

    it('selects the earliest caption for a repeated cue', function () {
        $this->workspace->bundle([
            'captions.vtt' => "WEBVTT\n\n00:00:02.000 --> 00:00:03.000\nsave\n\n00:00:08.000 --> 00:00:09.000\nsave\n",
        ]);

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $result = $this->workspace->loom('frame', [Workspace::Bundle, '--cue', 'save']);

        expect($result->exitCode)->toBe(0)
            ->and($result->json()['frames'][0]['ts'])->toBe(3.0);
    });

    it('returns bad input when a cue is absent or crosses caption boundaries', function () {
        $this->workspace->bundle([
            'captions.vtt' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nsave\n\n00:00:02.000 --> 00:00:03.000\nconfirmation\n",
        ]);

        $this->workspace->video(FixtureVideo::ScreenRecording);

        $absent = $this->workspace->loom('frame', [Workspace::Bundle, '--cue', 'delete']);
        $boundary = $this->workspace->loom('frame', [Workspace::Bundle, '--cue', 'save confirmation']);

        expect($absent->exitCode)->toBe(2)
            ->and($absent->json()['error'])->toContain('delete')
            ->and($boundary->exitCode)->toBe(2)
            ->and($boundary->json()['error'])->toContain('save confirmation');
    });

    it('keeps only changed frames inside a requested window', function () {
        $this->workspace->bundle();

        $this->workspace->video(['color=c=white', 'color=c=navy']);

        $result = $this->workspace->loom(
            'frame',
            [Workspace::Bundle, '--window', '0-12', '--every', '1', '--changed-only'],
        );

        expect($result->exitCode)->toBe(0)
            ->and($result->json()['frames'])->toHaveCount(2);
    });

    it('saves a crop and zoom with the resulting pixel dimensions', function () {
        $this->workspace->bundle();
        $this->workspace->write(Workspace::Bundle.'/captions.vtt', "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nzero\n");

        $this->workspace->video(['color=c=white']);

        $result = $this->workspace->loom(
            'frame',
            [Workspace::Bundle, '--cue', 'zero', '--region', '0,0,320,180', '--zoom', '2'],
        );

        expect($result->exitCode)->toBe(0)
            ->and($result->json()['frames'][0]['width'])->toBe(640)
            ->and($result->json()['frames'][0]['height'])->toBe(360);
    });

    it('allows a region that touches the saved frame edge', function () {
        $this->workspace->bundle([
            'captions.vtt' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nedge\n",
        ]);

        $this->workspace->video(['color=c=white']);

        $result = $this->workspace->loom('frame', [Workspace::Bundle, '--cue', 'edge', '--region', '320,180,320,180']);

        expect($result->exitCode)->toBe(0)
            ->and($result->json()['frames'][0]['width'])->toBe(320)
            ->and($result->json()['frames'][0]['height'])->toBe(180);
    });

    it('rejects an invalid window, region or zoom, and a window given with a cue', function () {
        $this->workspace->bundle();

        $this->workspace->video(['color=c=white']);

        $window = $this->workspace->loom('frame', [Workspace::Bundle, '--window', '4-2']);
        $region = $this->workspace->loom('frame', [Workspace::Bundle, '--cue', '0', '--region', '600,0,100,100']);
        $zoom = $this->workspace->loom('frame', [Workspace::Bundle, '--cue', '0', '--zoom', '5']);
        $both = $this->workspace->loom('frame', [Workspace::Bundle, '--window', '0-1', '--cue', '0']);

        expect($window->exitCode)->toBe(2)
            ->and($region->exitCode)->toBe(2)
            ->and($zoom->exitCode)->toBe(2)
            ->and($both->exitCode)->toBe(2);
    });

    it('caps the zoomed output at 1568 pixels', function () {
        $this->workspace->bundle([
            'captions.vtt' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nlarge\n",
        ]);

        $this->workspace->video(['color=c=white:s=1920x1080']);

        $result = $this->workspace->loom('frame', [Workspace::Bundle, '--cue', 'large', '--zoom', '4']);

        expect($result->exitCode)->toBe(0)
            ->and($result->json()['frames'][0]['width'])->toBe(1568);
    });
})->skip(! FixtureVideo::available(), 'ffmpeg is not installed');
