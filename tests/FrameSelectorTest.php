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

it('merges ticks, cuts and narrated moments, keeping the highest priority of close neighbours', function () {
    $candidates = FrameSelector::candidates(duration: 20, interval: 4, cuts: [7.5], moments: [8.2, 12.1]);

    expect(described($candidates))->toBe([
        '0.0 tick',
        '4.0 tick',
        // the narrated moment beats the 7.7 cut and the 8.0 tick that fall within 1.5 s of it
        '8.2 say',
        '12.1 say',
        '16.0 tick',
        '19.9 tick',
    ]);
});

it('never asks for a frame at the very end, where there is nothing left to decode', function () {
    $candidates = FrameSelector::candidates(duration: 12, interval: 4, cuts: [], moments: [12.6]);

    expect(described($candidates))->toBe(['0.0 tick', '4.0 tick', '8.0 tick', '11.9 say']);
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

    $white = new Frame(0, FrameReason::Tick, dhash: $whiteHash, mean: $whiteMean);
    $navy = new Frame(4, FrameReason::Tick, dhash: $navyHash, mean: $navyMean);
    $offWhite = new Frame(8, FrameReason::Tick, dhash: $whiteHash, mean: 247);

    expect($whiteHash)->toBe('0000000000000000')
        ->and($navyHash)->toBe($whiteHash)
        ->and(FrameSelector::isDuplicate($white, $navy))->toBeFalse()
        ->and(FrameSelector::isDuplicate($white, $offWhite))->toBeTrue();
});

it('drops a repeat of the last kept frame unless it is narrated or three ticks have passed', function () {
    $sameScreen = fn (float $seconds, FrameReason $reason) => new Frame(
        $seconds,
        $reason,
        dhash: '0000000000000000',
        mean: 200,
    );

    $kept = FrameSelector::dedupe(
        [
            $sameScreen(0, FrameReason::Tick),
            $sameScreen(4, FrameReason::Tick),
            $sameScreen(5, FrameReason::Say),
            $sameScreen(8, FrameReason::Tick),
            $sameScreen(12, FrameReason::Tick),
            $sameScreen(16, FrameReason::Tick),
            $sameScreen(18, FrameReason::Tick),
            new Frame(20, FrameReason::Cut, dhash: 'ffffffffffffffff', mean: 200),
        ],
        interval: 4,
    );

    // 18 survives because 13 s have passed since 5, more than three 4 s ticks
    expect(described($kept))->toBe(['0.0 tick', '5.0 say', '18.0 tick', '20.0 cut']);
});

it('spends a tight budget on narrated moments, then cuts, then ticks, in time order', function () {
    $frames = [
        new Frame(0, FrameReason::Tick),
        new Frame(3, FrameReason::Say),
        new Frame(6, FrameReason::Cut),
        new Frame(9, FrameReason::Tick),
    ];

    expect(described(FrameSelector::cap($frames, 2)))->toBe(['3.0 say', '6.0 cut'])
        ->and(FrameSelector::cap($frames, 4))->toBe($frames);
});

it('names a frame file by its minute, second and reason', function () {
    expect((new Frame(65.4, FrameReason::Say))->fileName())->toBe('f-0105-say.jpg');
});
