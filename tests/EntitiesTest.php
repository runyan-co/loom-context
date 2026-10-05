<?php

declare(strict_types=1);

/**
 * The entities CONTEXT.md lists, each as "kind value @first-seen".
 *
 * @return list<string>
 */
function entitiesIn(string $context): array
{
    preg_match_all('/^- \*\*(\w+)\*\* `(.+)` \(first at (\d\d:\d\d)\)$/m', $context, $found, PREG_SET_ORDER);

    return array_map(fn (array $entity) => $entity[1].' '.$entity[2].' @'.$entity[3], $found);
}

it('spots the identifiers any project has, once each, at the moment they are first said', function () {
    $context = contextFor($this->workspace, transcript(
        ['ts' => 0.4, 'value' => "So I'm on the invoices page for the acme workspace."],
        ['ts' => 4.1, 'value' => 'I click create invoice and you can see the 500 error right here.'],
        ['ts' => 9.0, 'value' => 'Pat at pat@example.com was charged $25.00 but only $19.50 came back.'],
        ['ts' => 15.2, 'value' => 'This is PROJ-1234 and again PROJ-1234, see https://admin.example.com/invoices?x=1.'],
    ));

    expect(entitiesIn($context))->toBe([
        'http_status 500 @00:04',
        'money $25.00 @00:09',
        'money $19.50 @00:09',
        'email pat@example.com @00:09',
        'ticket PROJ-1234 @00:15',
        // the sentence's full stop is not part of the link
        'url https://admin.example.com/invoices?x=1 @00:15',
    ]);
});

it('does not take dashed technical terms for ticket keys', function () {
    $context = contextFor($this->workspace, transcript(
        ['ts' => 1.0, 'value' => 'It is UTF-8, hashed with SHA-256, since COVID-19, per RFC-2616.'],
    ));

    expect(entitiesIn($context))->toBe([]);
});

it('does not include a sentence comma in a money entity', function () {
    $context = contextFor($this->workspace, transcript(['ts' => 1.0, 'value' => 'The total is $30, then I saved.']));

    expect(entitiesIn($context))->toContain('money $30 @00:01');
});

it('adds the project config\'s kinds, letting one replace the built-in of the same kind', function () {
    $this->workspace->write('.loom-context.json', ['entities' => [
        ['kind' => 'ticket', 'regex' => '\b(?:WEB|OPS)-\d{2,5}\b'],
        ['kind' => 'account', 'regex' => '\bACCT\d{6}\b'],
        [
            'kind' => 'tenant',
            'regex' => '\bworkspace\s+([a-z0-9][a-z0-9-]{2,})\b',
            'flags' => 'i',
            'lower' => true,
        ],
    ]]);

    $bundle = $this->workspace->bundle(transcript([
        'ts' => 1.0,
        'value' => 'Account ACCT204817 on workspace Acme-East, see OPS-431 not PROJ-12, it cost $5.',
    ]));

    $result = $this->workspace->loom('context', [$bundle]);

    expect(entitiesIn($this->workspace->readBundle('CONTEXT.md')))->toBe([
        'money $5 @00:01',
        'ticket OPS-431 @00:01',
        'account ACCT204817 @00:01',
        'tenant acme-east @00:01',
    ])
        ->and($result->json()['entities_config'])->toEndWith('/project/.loom-context.json');
});

it('reads the config named in LOOM_CONTEXT_CONFIG first, then the project\'s, then the user\'s', function () {
    $tagging = fn (string $kind) => ['entities' => [['kind' => $kind, 'regex' => 'zebra']]];

    $phrases = transcript(['ts' => 1.0, 'value' => 'a zebra']);

    expect(entitiesIn(contextFor($this->workspace, $phrases)))->toBe([]);

    $this->workspace->writeHome('.config/loom-context/config.json', $tagging('from_user'));

    expect(entitiesIn(contextFor($this->workspace, $phrases)))->toBe(['from_user zebra @00:01']);

    $this->workspace->write('.loom-context.json', $tagging('from_project'));

    expect(entitiesIn(contextFor($this->workspace, $phrases)))->toBe(['from_project zebra @00:01']);

    $named = $this->workspace->write('elsewhere/entities.json', $tagging('from_env'));

    expect(entitiesIn(contextFor($this->workspace, $phrases, ['LOOM_CONTEXT_CONFIG' => $named])))
        ->toBe(['from_env zebra @00:01']);
});

it('reports a config it cannot use and carries on with the built-ins', function () {
    $this->workspace->write('.loom-context.json', '{"entities": [{"kind": "broken", "regex": "("}]}');

    $bundle = $this->workspace->bundle(transcript(['ts' => 1.0, 'value' => 'see PROJ-1234']));

    $result = $this->workspace->loom('context', [$bundle]);

    expect($result->exitCode)->toBe(0)
        ->and($result->stderr)->toContain('[loom-context] ignoring', '.loom-context.json')
        ->and(entitiesIn($this->workspace->readBundle('CONTEXT.md')))->toBe(['ticket PROJ-1234 @00:01']);
});
