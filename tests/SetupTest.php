<?php

declare(strict_types=1);

use Tests\Support\Workspace;

// Every test starts on a machine that has everything, then takes away what it is about.
beforeEach(function () {
    $this->workspace->isolatePath();

    $this->workspace->spy('composer');

    $this->workspace->fakeYtDlp(['when' => ['--version'], 'stdout' => "2026.08.19\n"]);

    $this->workspace->command('ffmpeg', "echo 'ffmpeg version 9.0 Copyright (c) the FFmpeg developers'");

    $this->workspace->command('ffprobe', 'exit 0');

    $this->workspace->command('curl', "printf '200'");
});

it('passes without offering to install anything when every requirement is met', function () {
    $result = $this->workspace->script('setup.sh');

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain(
            sprintf('ok    php %s', PHP_VERSION),
            'ok    composer',
            'ok    skill dependencies installed',
            'ok    yt-dlp 2026.08.19',
            'ok    ffmpeg 9.0',
            'ok    egress to www.loom.com (200)',
        )
        ->and($result->stdout)->not->toContain('FAIL')
        ->and($result->stdout)->not->toContain('--install');
});

it('reports what is missing with the command that fixes it, and installs nothing', function () {
    $this->workspace->withoutCommand('yt-dlp');

    $this->workspace->withoutCommand('ffmpeg');

    $this->workspace->spy('brew');

    $result = $this->workspace->script('setup.sh');

    expect($result->exitCode)->toBe(1)
        ->and($result->stdout)->toContain(
            'FAIL  yt-dlp missing. Fix: brew install yt-dlp  [--install yt-dlp]',
            'FAIL  ffmpeg/ffprobe missing. Fix: brew install ffmpeg  [--install ffmpeg]',
            'Nothing was installed.',
            'Install in this order: yt-dlp ffmpeg',
        )
        ->and($this->workspace->commandsRun())->toBe([]);
});

it('runs exactly the one install action it is told to', function () {
    $this->workspace->withoutCommand('yt-dlp');

    $this->workspace->withoutCommand('ffmpeg');

    $this->workspace->spy('brew');

    $result = $this->workspace->script('setup.sh', ['--install', 'yt-dlp']);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain('running: brew install yt-dlp')
        ->and($this->workspace->commandsRun())->toBe(['brew install yt-dlp']);
});

it('offers the Homebrew formula for each requirement that is missing', function (string $tool, string $line) {
    $this->workspace->withoutCommand($tool);

    $this->workspace->spy('brew');

    expect($this->workspace->script('setup.sh')->stdout)->toContain($line);
})->with([
    'php' => ['php', 'FAIL  php >= 8.3 missing. Fix: brew install php  [--install php]'],
    'composer' => ['composer', 'FAIL  composer missing. Fix: brew install composer  [--install composer]'],
    'ffprobe' => ['ffprobe', 'FAIL  ffmpeg/ffprobe missing. Fix: brew install ffmpeg  [--install ffmpeg]'],
]);

it('picks the yt-dlp installer the machine has', function (string $installer, string $command) {
    $this->workspace->withoutCommand('yt-dlp');

    $this->workspace->spy($installer);

    $this->workspace->script('setup.sh', ['--install', 'yt-dlp']);

    expect($this->workspace->commandsRun())->toBe([$command]);
})->with([
    'Homebrew' => ['brew', 'brew install yt-dlp'],
    'pipx' => ['pipx', 'pipx install yt-dlp'],
    'pip' => ['python3', 'python3 -m pip install --user --upgrade yt-dlp'],
]);

it('offers to upgrade a yt-dlp from before 2026', function () {
    $this->workspace->fakeYtDlp(['when' => ['--version'], 'stdout' => "2025.06.09\n"]);

    $this->workspace->spy('pipx');

    $result = $this->workspace->script('setup.sh');

    expect($result->exitCode)->toBe(1)
        ->and($result->stdout)
        ->toContain('FAIL  yt-dlp 2025.06.09 is too old. Fix: pipx upgrade yt-dlp  [--install yt-dlp]');
});

it('installs the Composer packages into the checkout it was run from', function () {
    $checkout = $this->workspace->path('fresh-checkout');

    mkdir("{$checkout}/scripts", 0700, true);

    copy(Workspace::skillPath('scripts/setup.sh'), "{$checkout}/scripts/setup.sh");

    $check = $this->workspace->script("{$checkout}/scripts/setup.sh");

    expect($this->workspace->commandsRun())->toBe([]);

    $this->workspace->script("{$checkout}/scripts/setup.sh", ['--install', 'packages']);

    $installs = $this->workspace->commandsRun();

    expect($check->stdout)->toContain(
        'FAIL  skill dependencies missing. Fix: composer install --no-dev',
        '[--install packages]',
    )
        ->and($installs)->toHaveCount(1)
        ->and($installs[0])->toStartWith('composer install --no-dev --working-dir ')
        ->and($installs[0])->toEndWith('/fresh-checkout');
});

it('leaves a fix it cannot choose to the user', function () {
    $this->workspace->withoutCommand('composer');

    $check = $this->workspace->script('setup.sh');

    $install = $this->workspace->script('setup.sh', ['--install', 'composer']);

    expect($check->stdout)->toContain('FAIL  composer missing. Fix by hand: see https://getcomposer.org/download/')
        ->and($check->stdout)->not->toContain('--install')
        ->and($install->exitCode)->toBe(2)
        ->and($install->stderr)->toContain("'composer' has no install action this script can run");
});

it('never runs a fix that needs sudo', function () {
    $this->workspace->withoutCommand('ffmpeg');

    $this->workspace->spy('apt-get');

    $this->workspace->spy('sudo');

    $check = $this->workspace->script('setup.sh');

    $install = $this->workspace->script('setup.sh', ['--install', 'ffmpeg']);

    expect($check->stdout)
        ->toContain('FAIL  ffmpeg/ffprobe missing. Fix by hand (needs sudo): sudo apt-get install -y ffmpeg')
        ->and($install->exitCode)->toBe(2)
        ->and($this->workspace->commandsRun())->toBe([]);
});

it('asks at the terminal before each install and runs only what was answered yes', function () {
    $this->workspace->withoutCommand('yt-dlp');

    $this->workspace->withoutCommand('ffmpeg');

    $this->workspace->spy('brew');

    $result = $this->workspace->script('setup.sh', ['--ask'], stdin: "n\ny\n");

    expect($result->stdout)->toContain(
        'Run "brew install yt-dlp" to install yt-dlp? [y/N]',
        'skipped yt-dlp',
        'Run "brew install ffmpeg" to install ffmpeg? [y/N]',
    )
        ->and($this->workspace->commandsRun())->toBe(['brew install ffmpeg']);
});

it('takes no answer at the terminal as no', function () {
    $this->workspace->withoutCommand('yt-dlp');

    $this->workspace->spy('brew');

    $result = $this->workspace->script('setup.sh', ['--ask'], stdin: '');

    expect($result->exitCode)->toBe(1)
        ->and($result->stdout)->toContain('skipped yt-dlp')
        ->and($this->workspace->commandsRun())->toBe([]);
});

it('fails and names every host to allowlist when Loom cannot be reached', function () {
    $this->workspace->command('curl', "printf '000'\nexit 7");

    $result = $this->workspace->script('setup.sh');

    expect($result->exitCode)->toBe(1)
        ->and($result->stdout)->toContain(
            'FAIL  cannot reach www.loom.com (http 000)',
            'allowlist www.loom.com, cdn.loom.com and luna.loom.com',
        );
});

it('lists browser cookie sources by the names yt-dlp takes', function () {
    mkdir("{$this->workspace->home}/Library/Application Support/BraveSoftware/Brave-Browser", 0700, true);

    mkdir("{$this->workspace->home}/.mozilla/firefox", 0700, true);

    $result = $this->workspace->script('setup.sh');

    expect($result->stdout)->toContain('ok    browser cookie sources: brave firefox');
});

it('warns that private Looms need a cookie when no browser profile exists', function () {
    $result = $this->workspace->script('setup.sh');

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain('warn  no browser profile found; private Looms will need LOOM_COOKIE');
});

it('recognises the 1Password fallback configured through LOOM_OP_ITEM', function () {
    $this->workspace->command('op', 'exit 0');

    $result = $this->workspace->script('setup.sh', [], ['LOOM_OP_ITEM' => 'Loom session']);

    expect($result->stdout)->toContain('ok    1Password fallback available (LOOM_OP_ITEM set)');
});

it('sees a cookie that is set in .env, without printing it', function () {
    $this->workspace->dotenv("LOOM_COOKIE='s%3Asecret.sig'\n");

    $result = $this->workspace->script('setup.sh');

    expect($result->stdout)->toContain('ok    LOOM_COOKIE is set in .env (value not shown)')
        ->and($result->output())->not->toContain('secret');
});
