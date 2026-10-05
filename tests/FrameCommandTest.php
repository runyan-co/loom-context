<?php

declare(strict_types=1);

it('shows frame command options in its help output', function () {
    $result = $this->workspace->loom('frame', ['--help']);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain('--window', '--cue', '--changed-only', '--region', '--zoom');
});
