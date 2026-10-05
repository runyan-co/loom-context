<?php

declare(strict_types=1);

use LoomContext\Frame;
use LoomContext\FrameReason;
use LoomContext\FrameSelector;
use LoomContext\Phrase;

/**
 * @param  list<Frame>  $frames
 * @return list<string>
 */
function described(array $frames): array
{
    return array_map(fn (Frame $frame) => sprintf('%.1f %s', $frame->seconds, $frame->reason->value), $frames);
}

/**
 * A frame of one of two screens dedupe() can tell apart: A is flat, B is all gradient.
 */
function frameOfScreen(string $screen, float $seconds, FrameReason $reason): Frame
{
    $dhash = $screen === 'A' ? '0000000000000000' : 'ffffffffffffffff';

    return new Frame($seconds, $reason, dhash: $dhash, mean: 200);
}

it('recognises narration that points at the screen', function (string $said, bool $pointsAtScreen) {
    expect(FrameSelector::isMoment($said))->toBe($pointsAtScreen);
})->with([
    ['and then I click create', true],
    ['You can SEE the error', true],
    ["Anyway that's it, thanks.", false],
]);

it('takes the screen one second after a narrated moment starts', function () {
    $moments = FrameSelector::moments([new Phrase(4.1, 'I click here'), new Phrase(9.0, 'thanks')]);

    expect($moments)->toBe([5.1]);
});

it('puts settled screens, narrated moments and the end in time order, keeping close neighbours', function () {
    $states = [new Frame(0, FrameReason::State), new Frame(8, FrameReason::State)];

    $candidates = FrameSelector::candidates(duration: 20, states: $states, moments: [8.5, 12.1]);

    expect(described($candidates))->toBe(['0.0 state', '8.0 state', '8.5 say', '12.1 say', '19.9 end']);
});

it('holds a narrated moment past the end on the end frame', function () {
    $candidates = FrameSelector::candidates(duration: 12, states: [], moments: [12.6]);

    expect(described($candidates))->toBe(['11.9 end']);
});

it('adds one end frame, just before the duration', function () {
    $candidates = FrameSelector::candidates(duration: 13, states: [], moments: []);

    expect(described($candidates))->toBe(['12.9 end']);
});

it('adds one decodable end frame for a clip shorter than 0.1 seconds', function () {
    $states = [new Frame(0, FrameReason::State)];

    $candidates = FrameSelector::candidates(duration: 0.05, states: $states, moments: [0.5]);

    expect(described($candidates))->toBe(['0.0 end']);
});

it('hashes a thumbnail by comparing each pixel with its right-hand neighbour', function () {
    $brighteningRow = implode('', array_map(chr(...), range(10, 90, 10)));

    [$hash, $mean] = FrameSelector::signature(str_repeat($brighteningRow, 8));

    expect($hash)->toBe('ffffffffffffffff')
        ->and($mean)->toBe(50);
});

it('tells a flat light screen from a flat dark one by brightness, since both hash to zero', function () {
    [$whiteHash, $whiteMean] = FrameSelector::signature(str_repeat(chr(250), 72));
    [$navyHash, $navyMean] = FrameSelector::signature(str_repeat(chr(20), 72));

    $white = new Frame(0, FrameReason::State, dhash: $whiteHash, mean: $whiteMean);
    $navy = new Frame(4, FrameReason::State, dhash: $navyHash, mean: $navyMean);
    $offWhite = new Frame(8, FrameReason::State, dhash: $whiteHash, mean: 247);

    expect($whiteHash)->toBe('0000000000000000')
        ->and($navyHash)->toBe($whiteHash)
        ->and(FrameSelector::isDuplicate($white, $navy))->toBeFalse()
        ->and(FrameSelector::isDuplicate($white, $offWhite))->toBeTrue();
});

it('lets a narrated moment take the place of the screen it repeats', function () {
    $kept = FrameSelector::dedupe([
        frameOfScreen('A', 0, FrameReason::State),
        frameOfScreen('A', 5, FrameReason::Say),
        frameOfScreen('B', 9, FrameReason::State),
    ]);

    expect(described($kept))->toBe(['5.0 say', '9.0 state']);
});

it('keeps a narrated moment that shows a new screen, however close it is', function () {
    $kept = FrameSelector::dedupe([
        frameOfScreen('A', 0, FrameReason::State),
        frameOfScreen('B', 0.5, FrameReason::Say),
    ]);

    expect(described($kept))->toBe(['0.0 state', '0.5 say']);
});

it('lets a later narrated repeat take the place of an earlier one', function () {
    $kept = FrameSelector::dedupe([
        frameOfScreen('A', 2, FrameReason::Say),
        frameOfScreen('A', 3, FrameReason::Say),
    ]);

    expect(described($kept))->toBe(['3.0 say']);
});

it('drops a repeated screen, but never the end frame, whose place nothing takes', function () {
    $kept = FrameSelector::dedupe([
        frameOfScreen('A', 0, FrameReason::State),
        frameOfScreen('A', 4, FrameReason::State),
        frameOfScreen('A', 9.9, FrameReason::End),
        frameOfScreen('A', 9.9, FrameReason::Say),
    ]);

    expect(described($kept))->toBe(['0.0 state', '9.9 end']);
});

it('keeps a repeat once a long static stretch has passed', function () {
    $kept = FrameSelector::dedupe([
        frameOfScreen('A', 0, FrameReason::State),
        frameOfScreen('A', 13, FrameReason::Say),
    ]);

    expect(described($kept))->toBe(['0.0 state', '13.0 say']);
});

it('spends a tight budget on the end frame, then narrated moments, then settled screens, in time order', function () {
    $frames = [
        new Frame(0, FrameReason::State),
        new Frame(3, FrameReason::Say),
        new Frame(6, FrameReason::State),
        new Frame(9.9, FrameReason::End),
    ];

    expect(described(FrameSelector::cap($frames, 2)))->toBe(['3.0 say', '9.9 end'])
        ->and(FrameSelector::cap($frames, 4))->toBe($frames);
});

it('spreads capped screens across the recording and retains the end frame', function () {
    $states = array_map(fn (int $second) => new Frame((float) $second, FrameReason::State), range(0, 90, 10));

    $frames = [...$states, new Frame(99.9, FrameReason::End)];

    expect(array_map(fn (Frame $frame) => $frame->seconds, FrameSelector::cap($frames, 4)))
        ->toBe([0.0, 50.0, 90.0, 99.9]);
});

it('keeps a screen after it remains stable across two half-second samples', function () {
    $samples = [
        ['ts' => 0.0, 'dhash' => '0000000000000000', 'mean' => 200],
        ['ts' => 0.5, 'dhash' => '0000000000000000', 'mean' => 200],
        ['ts' => 1.0, 'dhash' => 'ffffffffffffffff', 'mean' => 200],
        ['ts' => 1.5, 'dhash' => 'ffffffffffffffff', 'mean' => 200],
    ];

    expect(described(FrameSelector::states($samples)))->toBe(['0.0 state', '1.0 state']);
});

it('ignores a one-sample visual fluctuation', function () {
    $samples = [
        ['ts' => 0.0, 'dhash' => '0000000000000000', 'mean' => 200],
        ['ts' => 0.5, 'dhash' => 'ffffffffffffffff', 'mean' => 200],
        ['ts' => 1.0, 'dhash' => '0000000000000000', 'mean' => 200],
        ['ts' => 1.5, 'dhash' => '0000000000000000', 'mean' => 200],
    ];

    expect(described(FrameSelector::states($samples)))->toBe(['1.0 state']);
});

it('names a frame file by its minute, second and reason', function () {
    expect((new Frame(65.4, FrameReason::Say))->fileName())->toBe('f-0105-say.jpg');
});

it('numbers a second frame of the same reason in the same second', function () {
    expect((new Frame(65.4, FrameReason::Say))->fileName(copy: 2))->toBe('f-0105-say-2.jpg');
});
