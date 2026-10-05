<?php

declare(strict_types=1);

use Tests\Support\Workspace;

beforeEach(function () {
    $this->environment = [
        'COOKIE_SCRIPT' => "{$this->workspace->checkout}/scripts/loom_cookie.sh",
        'OP_CALLS' => $this->workspace->path('op-calls.log'),
    ];
});

it('exports the cookie from the 1Password item without printing it', function () {
    $this->workspace->command('op', <<<'SH'
        echo "$*" >> "$OP_CALLS"

        if [ "$1" = item ]; then
          printf 's%%3Asecret.sig'
        fi
        SH);

    $result = $this->workspace->bash(
        'source "$COOKIE_SCRIPT" && bash -c \'[ "$LOOM_COOKIE" = "s%3Asecret.sig" ]\' && echo exported',
        [...$this->environment, 'LOOM_OP_ITEM' => 'Loom session'],
    );

    expect($result->stdout)->toBe("exported\n")
        ->and($result->output())->not->toContain('secret')
        ->and($this->workspace->read('op-calls.log'))
        ->toContain('item get Loom session --fields label=connect.sid --reveal');
});

it('explains what to set when no 1Password item is configured', function () {
    $this->workspace->command('op', 'exit 0');

    $result = $this->workspace->bash(
        'source "$COOKIE_SCRIPT"; echo "status=$? cookie=${LOOM_COOKIE:-unset}"',
        $this->environment,
    );

    expect($result->stdout)->toBe("status=1 cookie=unset\n")
        ->and($result->stderr)->toContain('Set LOOM_OP_ITEM');
});

it('leaves LOOM_COOKIE unset when the item has no connect.sid field', function () {
    $this->workspace->command('op', 'exit 0');

    $result = $this->workspace->bash(
        'source "$COOKIE_SCRIPT"; echo "status=$? cookie=${LOOM_COOKIE:-unset}"',
        [...$this->environment, 'LOOM_OP_ITEM' => 'Loom session'],
    );

    expect($result->stdout)->toBe("status=1 cookie=unset\n")
        ->and($result->stderr)->toContain("Item 'Loom session' has no 'connect.sid' field");
});

it('takes the 1Password item from .env when the shell does not name one', function () {
    $this->workspace->dotenv("LOOM_OP_ITEM=\"Loom session\"\n");

    $this->workspace->command('op', <<<'SH'
        echo "$*" >> "$OP_CALLS"

        if [ "$1" = item ]; then
          printf 's%%3Asecret.sig'
        fi
        SH);

    $result = $this->workspace->bash('source "$COOKIE_SCRIPT" && echo exported', $this->environment);

    expect($result->stdout)->toBe("exported\n")
        ->and($this->workspace->read('op-calls.log'))
        ->toContain('item get Loom session --fields label=connect.sid --reveal');
});
