<?php

declare(strict_types=1);

// Router for the FakeLoom server. See Tests\Support\FakeLoom.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/files/')) {
    readfile(getenv('FAKE_LOOM_FILES').'/'.basename($path));

    return;
}

file_put_contents(
    getenv('FAKE_LOOM_REQUESTS'),
    json_encode([
        'cookie' => $_SERVER['HTTP_COOKIE'] ?? null,
        'body' => json_decode(file_get_contents('php://input'), true),
    ])."\n",
    FILE_APPEND,
);

header('Content-Type: application/json');

readfile(getenv('FAKE_LOOM_RESPONSE'));
