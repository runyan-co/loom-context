<?php

declare(strict_types=1);

use Tests\Support\Workspace;

/**
 * @return list<string>
 */
function timelineOf(string $context): array
{
    preg_match('/^## Timeline\n\n(.*?)\n\n/ms', $context."\n", $section);

    return explode("\n", $section[1]);
}

it('lists phrases in time order and leaves out empty ones', function () {
    $context = contextFor($this->workspace, transcript(
        ['ts' => 9.0, 'value' => 'second'],
        ['ts' => 2.0, 'value' => '   '],
        ['ts' => 1.0, 'value' => 'first'],
    ));

    expect(timelineOf($context))->toBe(['[00:01] "first"', '[00:09] "second"']);
});

it('falls back to the captions and strips their markup', function () {
    $captions = <<<'VTT'
        WEBVTT

        00:00:00.400 --> 00:00:03.900
        So I'm on the invoices page.

        00:00:04.100 --> 00:00:08.000
        I click <b>create</b> and see the error.
        VTT;

    $context = contextFor($this->workspace, ['captions.vtt' => $captions]);

    expect(timelineOf($context))->toBe([
        '[00:00] "So I\'m on the invoices page."',
        '[00:04] "I click create and see the error."',
    ]);
});

it('prefers the phrase transcript when the bundle has captions too', function () {
    $context = contextFor($this->workspace, [
        ...transcript(['ts' => 1.0, 'value' => 'from the transcript']),
        'captions.vtt' => "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nfrom the captions\n",
    ]);

    expect(timelineOf($context))->toBe(['[00:01] "from the transcript"']);
});

it('writes timestamps as mm:ss and lets hours roll into the minutes', function () {
    $context = contextFor($this->workspace, transcript(
        ['ts' => 0, 'value' => 'start'],
        ['ts' => 65.4, 'value' => 'a minute in'],
        ['ts' => 3725, 'value' => 'an hour in'],
    ));

    expect(timelineOf($context))->toBe([
        '[00:00] "start"',
        '[01:05] "a minute in"',
        '[62:05] "an hour in"',
    ]);
});

it('points each phrase at the nearest frame within two seconds, else at the latest earlier one', function () {
    $context = contextFor($this->workspace, [
        ...transcript(
            ['ts' => 0.4, 'value' => 'intro'],
            ['ts' => 4.1, 'value' => 'I click here'],
            ['ts' => 12.0, 'value' => 'still talking'],
        ),
        'frames.json' => ['frames' => [
            ['ts' => 0.0, 'path' => 'frames/f-0000-tick.jpg', 'reason' => 'tick'],
            ['ts' => 5.1, 'path' => 'frames/f-0005-say.jpg', 'reason' => 'say'],
            ['ts' => 20.0, 'path' => 'frames/f-0020-tick.jpg', 'reason' => 'tick'],
        ]],
    ]);

    expect(timelineOf($context))->toBe([
        '[00:00] "intro" ▶ frames/f-0000-tick.jpg',
        '[00:04] "I click here" ▶ frames/f-0005-say.jpg',
        '[00:12] "still talking" ▶ frames/f-0005-say.jpg',
        '[00:20] ▶ frames/f-0020-tick.jpg',
    ]);
});

it('renders every section the bundle has material for', function () {
    $context = contextFor($this->workspace, [
        ...transcript(['ts' => 4.1, 'value' => 'I click create and see the 500 error']),
        'meta.json' => [
            'video_id' => Workspace::VideoId,
            'title' => 'Invoice 500',
            'uploader' => 'Jordan',
            'upload_date' => '20261001',
            'duration_s' => 25,
            'webpage_url' => Workspace::ShareUrl,
            'source' => 'yt-dlp',
        ],
        'brief.md' => "Jordan shows a 500.\n",
        'chapters.json' => [['start_time' => 0, 'title' => 'Intro']],
        'frames.json' => ['frames' => [['ts' => 5.1, 'path' => 'frames/f-0005-say.jpg', 'reason' => 'say']]],
    ]);

    $shareUrl = Workspace::ShareUrl;

    expect($context)->toBe(<<<MARKDOWN
        # Invoice 500 — Loom context

        Source: {$shareUrl} · Recorded by Jordan on 20261001 · 00:25 · fetched via yt-dlp

        ## AI brief (Loom)

        Jordan shows a 500.

        ## Chapters

        - [00:00] Intro

        ## Entities spotted

        - **http_status** `500` (first at 00:04)

        ## Timeline

        [00:04] "I click create and see the 500 error" ▶ frames/f-0005-say.jpg

        ## Frames

        | ts | file | reason |
        |---|---|---|
        | 00:05 | frames/f-0005-say.jpg | say |

        Overview: frames/contact-sheet.jpg

        MARKDOWN);
});

it('says so when the bundle has neither a transcript nor frames', function () {
    $context = contextFor($this->workspace, []);

    expect($context)->toStartWith('# '.Workspace::VideoId.' — Loom context')
        ->and(timelineOf($context))->toBe(['_No transcript and no frames were available._'])
        ->and($context)->not->toContain('## Frames');
});

it('prints the manifest as its last line and writes the same thing to manifest.json', function () {
    $bundle = $this->workspace->bundle([
        ...transcript(['ts' => 1.0, 'value' => 'first'], ['ts' => 9.0, 'value' => 'second']),
        'brief.md' => 'A brief.',
        'frames.json' => ['frames' => [
            ['ts' => 0.0, 'path' => 'frames/f-0000-tick.jpg', 'reason' => 'tick'],
            ['ts' => 4.0, 'path' => 'frames/f-0004-tick.jpg', 'reason' => 'tick'],
            ['ts' => 8.0, 'path' => 'frames/f-0008-tick.jpg', 'reason' => 'tick'],
        ]],
    ]);

    $result = $this->workspace->loom('context', [$bundle]);

    expect($result->json())->toEqual([
        'video_id' => Workspace::VideoId,
        'dir' => $bundle,
        'context' => "{$bundle}/CONTEXT.md",
        'phrases' => 2,
        'frames' => 3,
        'chapters' => 0,
        'entities' => 0,
        'entities_config' => null,
        'brief' => true,
        'approx_frame_tokens' => 4500,
        'notes' => [],
    ])
        ->and(json_decode($this->workspace->readBundle('manifest.json'), true))->toEqual($result->json());
});

it('keeps a partial bundle and says what is missing when there is no transcript and no MP4', function () {
    $this->workspace->fakeYtDlp(
        ['when' => ['--dump-single-json'], 'info' => loomInfo()],
        [
            'when' => ['-o'],
            'stderr' => 'ERROR: unable to download video data: HTTP Error 403: Forbidden',
            'exit' => 1,
        ],
    );

    $result = $this->workspace->loom('context', [Workspace::ShareUrl, '--cookies-from-browser', 'none']);

    expect($result->exitCode)->toBe(0)
        ->and($result->json()['notes'])->toHaveCount(2)
        ->and($this->workspace->readBundle('CONTEXT.md'))->toContain(
            '> Loom had no transcript for this video',
            '> The MP4 could not be downloaded',
        );
});

it('stops with the fetch error and its exit code when the link cannot be fetched', function () {
    $result = $this->workspace->loom('context', ['https://youtu.be/abc']);

    expect($result->exitCode)->toBe(2)
        ->and($result->json()['error'])->toContain('not a Loom video URL or id');
});
