<?php

declare(strict_types=1);

use LoomContext\YtDlpError;

it('reads what went wrong from what yt-dlp printed', function (string $stderr, YtDlpError $error) {
    expect(YtDlpError::fromStderr($stderr))->toBe($error);
})->with([
    'password needed' => [
        'ERROR: [loom] x: This video is password-protected, use the --video-password option',
        YtDlpError::Password,
    ],
    'password wrong' => ['ERROR: [loom] x: Invalid video password', YtDlpError::Password],
    'blocked by a proxy' => [
        "ProxyError('Unable to connect to proxy', OSError('Tunnel connection failed: 403 Forbidden'))",
        YtDlpError::Network,
    ],
    'private, deleted or mistyped' => [
        'ERROR: [loom] x: No video formats found!; please report this issue on  https://github.com/yt-dlp',
        YtDlpError::Unavailable,
    ],
    'private' => ['ERROR: [loom] x: This video is private', YtDlpError::Private],
    'Loom changed its API' => [
        'ERROR: [loom] x: Failed to download GraphQL JSON: HTTP Error 400',
        YtDlpError::Outdated,
    ],
    'anything else' => ['something else', YtDlpError::Unknown],
]);
