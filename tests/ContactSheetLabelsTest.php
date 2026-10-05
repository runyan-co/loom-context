<?php

declare(strict_types=1);

use LoomContext\ContactSheetLabels;

it('draws a timestamp as white glyphs on a black box just big enough for it', function () {
    $pgm = ContactSheetLabels::pgm('01:23');

    [$header, $pixels] = explode("255\n", $pgm, 2);

    // Five glyphs five wide with a column between them, and two pixels of box all round.
    expect($header)->toBe("P5\n33 11\n")
        ->and(strlen($pixels))->toBe(33 * 11)
        ->and($pixels[0])->toBe("\0")
        ->and(substr_count($pixels, "\xff"))->toBeGreaterThan(0);
});

it('has a glyph for every character a timestamp uses', function (string $character) {
    [, $pixels] = explode("255\n", ContactSheetLabels::pgm($character), 2);

    expect(substr_count($pixels, "\xff"))->toBeGreaterThan(0);
})->with(str_split('0123456789:'));
