<?php

declare(strict_types=1);

use Tests\Support\Workspace;

it('reads the video id out of every way a Loom link gets pasted', function (string $pasted) {
    $this->workspace->fakeYtDlp(['info' => loomInfo()]);

    $result = $this->workspace->loom('fetch', [$pasted, '--cookies-from-browser', 'none', '--skip-video']);

    $arguments = $this->workspace->ytDlpCalls()[0]['arguments'];

    expect($result->exitCode)->toBe(0)
        ->and($result->json()['video_id'])->toBe(Workspace::VideoId)
        ->and($result->json()['dir'])->toBe(Workspace::Bundle)
        ->and(end($arguments))->toBe(Workspace::ShareUrl);
})->with([
    'share link' => Workspace::ShareUrl,
    'share link with a query string' => Workspace::ShareUrl.'?sid=abc&t=12',
    'embed link' => 'https://www.loom.com/embed/'.Workspace::VideoId,
    'link without a scheme' => 'loom.com/share/'.Workspace::VideoId,
    'bare id' => Workspace::VideoId,
    'Slack-wrapped link' => '<'.Workspace::ShareUrl.'|loom.com/share/…>',
]);

it('refuses anything that is not a Loom video without calling yt-dlp', function (string $input) {
    $result = $this->workspace->loom('fetch', [$input]);

    expect($result->exitCode)->toBe(2)
        ->and($result->json()['error'])->toContain('expected https://www.loom.com/share/<32 hex>')
        ->and($this->workspace->ytDlpCalls())->toBe([]);
})->with([
    'another video site' => 'https://youtu.be/abc',
    'the Loom home page' => 'https://www.loom.com/',
    'a ticket key' => 'PROJ-1234',
    'nothing' => '',
]);

it('exits 5 and says how to install yt-dlp when it is missing', function () {
    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl], ['PATH' => $this->workspace->home]);

    expect($result->exitCode)->toBe(5)
        ->and($result->json()['error'])->toContain('yt-dlp is not installed', 'brew install yt-dlp');
});

it('asks yt-dlp for the metadata and the transcript anonymously first', function () {
    $this->workspace->fakeYtDlp(['info' => loomInfo()]);

    $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video']);

    $calls = $this->workspace->ytDlpCalls();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['arguments'])->toContain('--skip-download', '--dump-single-json', '--write-subs')
        ->and($calls[0]['arguments'])->not->toContain('--cookies-from-browser')
        ->and($calls[0]['arguments'])->not->toContain('--cookies');
});

it('reports what it fetched and saves the metadata and chapters', function () {
    $chapters = [['start_time' => 0, 'title' => 'Intro']];

    $this->workspace->fakeYtDlp(['info' => loomInfo(['chapters' => $chapters])]);

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video']);

    $meta = json_decode($this->workspace->readBundle('meta.json'), true);

    expect($result->json())->toEqual([
        'video_id' => Workspace::VideoId,
        'dir' => Workspace::Bundle,
        'title' => 'Invoice 500',
        'duration_s' => 30,
        'transcript' => 'none',
        'chapters' => true,
        'mp4' => false,
        'source' => 'yt-dlp',
    ])
        ->and($meta['uploader'])->toBe('Jordan')
        ->and($meta['upload_date'])->toBe('20261001')
        ->and($meta['webpage_url'])->toBe(Workspace::ShareUrl)
        ->and(json_decode($this->workspace->readBundle('chapters.json'), true))->toBe($chapters);
});

it('downloads the MP4 into the bundle, passing the video password along', function () {
    $this->workspace->fakeYtDlp(
        ['when' => ['--dump-single-json'], 'info' => loomInfo()],
        ['when' => ['-o'], 'video' => $this->workspace->write('served.mp4', 'mp4 bytes')],
    );

    $result = $this->workspace->loom(
        'fetch',
        [Workspace::ShareUrl, '--cookies-from-browser', 'none', '--password', 'pw'],
    );

    $download = $this->workspace->ytDlpCalls()[1]['arguments'];

    expect($result->json()['mp4'])->toBeTrue()
        ->and($this->workspace->readBundle('video.mp4'))->toBe('mp4 bytes')
        ->and($download)->toContain('-o', sprintf('%s/video.%%(ext)s', Workspace::Bundle), '--video-password', 'pw')
        ->and($download)->not->toContain('--skip-download')
        ->and($download)->not->toContain('--write-subs');
});

it('stops at the first rung with the exit code and the fix for what yt-dlp reported', function (
    string $stderr,
    int $exitCode,
    string $fix,
) {
    $this->workspace->fakeYtDlp(['stderr' => $stderr, 'exit' => 1]);

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl]);

    expect($result->exitCode)->toBe($exitCode)
        ->and($result->json()['exit'])->toBe($exitCode)
        ->and($result->json()['error'])->toContain($fix)
        ->and($this->workspace->ytDlpCalls())->toHaveCount(1);
})->with([
    'password protected' => [
        'ERROR: [loom] x: This video is password-protected, use the --video-password option',
        4,
        'Re-run with --password',
    ],
    'no route to Loom' => [
        "ProxyError('Unable to connect to proxy', OSError('Tunnel connection failed: 403 Forbidden'))",
        3,
        'allowlist www.loom.com, cdn.loom.com and luna.loom.com',
    ],
    'Loom changed its API' => [
        'ERROR: [loom] x: Failed to download GraphQL JSON: HTTP Error 400',
        5,
        'pip install -U yt-dlp',
    ],
]);

it('says the password was rejected rather than asking for one again', function () {
    $this->workspace->fakeYtDlp(['stderr' => 'ERROR: [loom] x: Invalid video password', 'exit' => 1]);

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl, '--password', 'nope']);

    expect($result->exitCode)->toBe(4)
        ->and($result->json()['error'])->toBe('Loom rejected the video password.');
});

it('climbs to browser cookies when yt-dlp finds no formats, instead of blaming an outdated yt-dlp', function () {
    $this->workspace->fakeYtDlp(
        ['when' => ['--cookies-from-browser', 'brave'], 'info' => loomInfo()],
        findsNoFormats(),
    );

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video']);

    expect($result->exitCode)->toBe(0)
        ->and($result->stderr)->toContain('anonymous: unavailable', 'browser:chrome: unavailable', 'via browser:brave')
        ->and($this->workspace->ytDlpCalls())->toHaveCount(3);
});

it('gives up with exit 4 and every fix once the whole ladder has failed', function () {
    $this->workspace->fakeYtDlp(findsNoFormats());

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl]);

    expect($result->exitCode)->toBe(4)
        ->and($result->json()['error'])->toContain(
            'private, deleted, or the link is wrong',
            'log into loom.com',
            'LOOM_COOKIE',
            "'anyone with the link'",
        )
        // anonymous, then chrome, brave, edge, chromium, firefox, safari
        ->and($this->workspace->ytDlpCalls())->toHaveCount(7);
});

it('hands LOOM_COOKIE to yt-dlp as an owner-only cookie jar and deletes it afterwards', function () {
    $this->workspace->fakeYtDlp(
        ['when' => ['--cookies'], 'info' => loomInfo()],
        findsNoFormats(),
    );

    $result = $this->workspace->loom(
        'fetch',
        [Workspace::ShareUrl, '--cookies-from-browser', 'none', '--skip-video'],
        ['LOOM_COOKIE' => 'connect.sid=s%3Aabc.def'],
    );

    $withJar = $this->workspace->ytDlpCalls()[1];

    expect($result->exitCode)->toBe(0)
        ->and($result->stderr)->toContain('fetched metadata via env:LOOM_COOKIE')
        ->and($withJar['cookie_jar']['mode'])->toBe('0600')
        ->and($withJar['cookie_jar']['contents'])->toStartWith('# Netscape HTTP Cookie File')
        ->and($withJar['cookie_jar']['contents'])->toContain("\tconnect.sid\ts%3Aabc.def\n")
        ->and(file_exists($withJar['cookie_jar']['path']))->toBeFalse()
        ->and(implode(' ', $withJar['arguments']))->not->toContain('s%3Aabc.def')
        ->and($result->output())->not->toContain('s%3Aabc.def');
});

it('saves the transcript and the captions yt-dlp found, telling them apart by content', function () {
    $loom = $this->workspace->fakeLoom();

    $tracks = [
        ['url' => $loom->fileUrl('captions.vtt')],
        ['url' => $loom->fileUrl('transcript.json')],
    ];

    $this->workspace->fakeYtDlp(['info' => loomInfo(['subtitles' => ['en' => $tracks]])]);

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video']);

    expect($result->json()['transcript'])->toBe('json')
        ->and($this->workspace->readBundle('transcript.json'))
        ->toBe(file_get_contents(Workspace::fixture('transcript.json')))
        ->and($this->workspace->readBundle('captions.vtt'))
        ->toBe(file_get_contents(Workspace::fixture('captions.vtt')))
        // yt-dlp already had the links, so Loom is not asked again
        ->and($loom->requests())->toBe([]);
});

it('asks Loom for the transcript itself when yt-dlp comes back without one', function () {
    $loom = $this->workspace->fakeLoom();

    $loom->respondWith(['data' => ['fetchVideoTranscript' => [
        '__typename' => 'VideoTranscriptDetails',
        'source_url' => $loom->fileUrl('transcript.json'),
        'captions_source_url' => $loom->fileUrl('captions.vtt'),
    ]]]);

    $this->workspace->fakeYtDlp(['info' => loomInfo()]);

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video', '--password', 'pw']);

    $request = $loom->requests()[0];

    expect($result->json()['transcript'])->toBe('json')
        ->and($this->workspace->readBundle('transcript.json'))
        ->toBe(file_get_contents(Workspace::fixture('transcript.json')))
        ->and(is_file($this->workspace->bundlePath('captions.vtt')))->toBeTrue()
        ->and($request['body']['variables'])->toBe(['videoId' => Workspace::VideoId, 'password' => 'pw'])
        // Loom removed VideoTranscriptDetails.id; a query that still selects it gets HTTP 400 and no transcript.
        ->and($request['body']['query'])->not->toMatch('/\bid\b/')
        ->and($request['cookie'])->toBeNull();
});

it('sends the Loom cookie with that request when LOOM_COOKIE is what opened the video', function () {
    $loom = $this->workspace->fakeLoom();

    $this->workspace->fakeYtDlp(
        ['when' => ['--cookies'], 'info' => loomInfo()],
        findsNoFormats(),
    );

    $this->workspace->loom(
        'fetch',
        [Workspace::ShareUrl, '--cookies-from-browser', 'none', '--skip-video'],
        ['LOOM_COOKIE' => 's%3Aabc.def'],
    );

    expect($loom->requests()[0]['cookie'])->toBe('connect.sid=s%3Aabc.def');
});

it('sends the Loom cookie with that request when a browser opened the video', function () {
    $loom = $this->workspace->fakeLoom();

    $this->workspace->fakeYtDlp(
        ['when' => ['--cookies-from-browser', 'chrome'], 'info' => loomInfo()],
        findsNoFormats(),
    );

    $this->workspace->loom(
        'fetch',
        [Workspace::ShareUrl, '--cookies-from-browser', 'chrome', '--skip-video'],
        ['LOOM_COOKIE' => 's%3Aabc.def'],
    );

    expect($loom->requests()[0]['cookie'])->toBe('connect.sid=s%3Aabc.def');
});

it('keeps LOOM_COOKIE to itself when the video opened anonymously', function () {
    $loom = $this->workspace->fakeLoom();

    $this->workspace->fakeYtDlp(['info' => loomInfo()]);

    $this->workspace->loom(
        'fetch',
        [Workspace::ShareUrl, '--skip-video'],
        ['LOOM_COOKIE' => 's%3Aabc.def'],
    );

    expect($loom->requests()[0]['cookie'])->toBeNull()
        ->and($this->workspace->ytDlpCalls()[0]['cookie_jar'])->toBeNull();
});

it('carries on without a transcript when Loom has none', function () {
    $loom = $this->workspace->fakeLoom();

    $loom->respondWith(['data' => ['fetchVideoTranscript' => ['__typename' => 'InvalidRequestWarning']]]);

    $this->workspace->fakeYtDlp(['info' => loomInfo()]);

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video']);

    expect($result->exitCode)->toBe(0)
        ->and($result->json()['transcript'])->toBe('none');
});

it('keeps a transcript already in the bundle when the lookup fails', function () {
    $this->workspace->bundle(transcript(['ts' => 1.0, 'value' => 'written from another source']));

    $this->workspace->fakeYtDlp(['info' => loomInfo()]);

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video']);

    expect($result->exitCode)->toBe(0)
        ->and($result->stderr)->toContain('transcript lookup failed')
        ->and($result->json()['transcript'])->toBe('json')
        ->and($this->workspace->readBundle('transcript.json'))->toContain('written from another source');
});

it('makes the output folder ignore itself in git, leaving an existing ignore file alone', function () {
    $this->workspace->fakeYtDlp(['info' => loomInfo()]);

    expect(file_exists($this->workspace->path('.loom')))->toBeFalse();

    $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video']);

    expect($this->workspace->read('.loom/.gitignore'))->toBe("*\n");

    $this->workspace->write('.loom/.gitignore', "custom\n");

    $this->workspace->loom('fetch', [Workspace::ShareUrl, '--skip-video', '--refresh']);

    expect($this->workspace->read('.loom/.gitignore'))->toBe("custom\n");
});

it('reuses a fetched bundle until told to refresh', function () {
    $this->workspace->fakeYtDlp(['info' => loomInfo()]);

    $fetch = fn (string ...$flags) => $this->workspace->loom(
        'fetch',
        [Workspace::ShareUrl, '--skip-video', ...$flags],
    );

    expect($this->workspace->ytDlpCalls())->toHaveCount(0);

    $fetch();

    $fetch();

    expect($this->workspace->ytDlpCalls())->toHaveCount(1);

    $fetch('--refresh');

    expect($this->workspace->ytDlpCalls())->toHaveCount(2);
});

it('reads LOOM_COOKIE from the .env beside the entry file', function () {
    $this->workspace->dotenv("LOOM_COOKIE='s%3Afrom.dotenv'\n");

    $this->workspace->fakeYtDlp(['when' => ['--cookies'], 'info' => loomInfo()], findsNoFormats());

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl, '--cookies-from-browser', 'none', '--skip-video']);

    expect($result->stderr)->toContain('fetched metadata via env:LOOM_COOKIE')
        ->and($this->workspace->ytDlpCalls()[1]['cookie_jar']['contents'])->toContain("\tconnect.sid\ts%3Afrom.dotenv\n")
        ->and($result->output())->not->toContain('from.dotenv');
});

it('lets a variable already in the environment win over .env', function () {
    $this->workspace->dotenv("LOOM_COOKIE='s%3Afrom.dotenv'\n");

    $this->workspace->fakeYtDlp(['when' => ['--cookies'], 'info' => loomInfo()], findsNoFormats());

    $this->workspace->loom(
        'fetch',
        [Workspace::ShareUrl, '--cookies-from-browser', 'none', '--skip-video'],
        ['LOOM_COOKIE' => 's%3Afrom.shell'],
    );

    expect($this->workspace->ytDlpCalls()[1]['cookie_jar']['contents'])->toContain("\tconnect.sid\ts%3Afrom.shell\n");
});

it('reports a .env it cannot parse as bad input', function () {
    $this->workspace->dotenv("LOOM_COOKIE=\"never closed\n");

    $result = $this->workspace->loom('fetch', [Workspace::ShareUrl]);

    expect($result->exitCode)->toBe(2)
        ->and($result->json()['error'])->toContain('.env could not be read')
        ->and($this->workspace->ytDlpCalls())->toBe([]);
});
