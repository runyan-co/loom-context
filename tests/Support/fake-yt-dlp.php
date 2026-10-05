<?php

declare(strict_types=1);

// Stand-in for yt-dlp. See Tests\Support\Workspace::fakeYtDlp() for the rule format.

$arguments = array_slice($argv, 1);

$valueOf = function (string $flag) use ($arguments): ?string {
    $position = array_search($flag, $arguments, true);

    return $position === false ? null : $arguments[$position + 1];
};

$cookieJar = $valueOf('--cookies');

file_put_contents(
    getenv('FAKE_YTDLP_CALLS'),
    json_encode([
        'arguments' => $arguments,
        'cookie_jar' => $cookieJar === null ? null : [
            'path' => $cookieJar,
            'mode' => substr(sprintf('%o', fileperms($cookieJar)), -4),
            'contents' => file_get_contents($cookieJar),
        ],
    ])."\n",
    FILE_APPEND,
);

foreach (json_decode(file_get_contents(getenv('FAKE_YTDLP_RULES')), true) as $rule) {
    $applies = array_diff($rule['when'] ?? [], $arguments) === []
        && array_intersect($rule['without'] ?? [], $arguments) === [];

    if (! $applies) {
        continue;
    }

    if (isset($rule['video'])) {
        copy($rule['video'], str_replace('%(ext)s', 'mp4', $valueOf('-o')));
    }

    if (isset($rule['info'])) {
        echo json_encode($rule['info']), "\n";
    }

    echo $rule['stdout'] ?? '';

    fwrite(STDERR, $rule['stderr'] ?? '');

    exit($rule['exit'] ?? 0);
}

fwrite(STDERR, "fake yt-dlp: no rule matches these arguments\n");

exit(1);
