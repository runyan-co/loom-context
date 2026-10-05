<?php

declare(strict_types=1);

namespace LoomContext;

/**
 * Timestamp labels for contact-sheet tiles, drawn from a bundled bitmap font so they do not depend on ffmpeg's
 * optional drawtext filter.
 */
class ContactSheetLabels
{
    private const GlyphHeight = 7;

    // Five columns of glyph and one of spacing.
    private const Advance = 6;

    // The black box around the text, which keeps it readable on a light screen and a dark one alike.
    private const Padding = 2;

    private const Font = [
        '0' => [
            '01110',
            '10001',
            '10011',
            '10101',
            '11001',
            '10001',
            '01110',
        ],
        '1' => [
            '00100',
            '01100',
            '00100',
            '00100',
            '00100',
            '00100',
            '01110',
        ],
        '2' => [
            '01110',
            '10001',
            '00001',
            '00010',
            '00100',
            '01000',
            '11111',
        ],
        '3' => [
            '01110',
            '10001',
            '00001',
            '00110',
            '00001',
            '10001',
            '01110',
        ],
        '4' => [
            '00010',
            '00110',
            '01010',
            '10010',
            '11111',
            '00010',
            '00010',
        ],
        '5' => [
            '11111',
            '10000',
            '11110',
            '00001',
            '00001',
            '10001',
            '01110',
        ],
        '6' => [
            '00110',
            '01000',
            '10000',
            '11110',
            '10001',
            '10001',
            '01110',
        ],
        '7' => [
            '11111',
            '00001',
            '00010',
            '00100',
            '01000',
            '01000',
            '01000',
        ],
        '8' => [
            '01110',
            '10001',
            '10001',
            '01110',
            '10001',
            '10001',
            '01110',
        ],
        '9' => [
            '01110',
            '10001',
            '10001',
            '01111',
            '00001',
            '00010',
            '01100',
        ],
        ':' => [
            '00000',
            '00100',
            '00100',
            '00000',
            '00100',
            '00100',
            '00000',
        ],
        '.' => [
            '00000',
            '00000',
            '00000',
            '00000',
            '00000',
            '00110',
            '00110',
        ],
    ];

    /**
     * The label in white on a black PGM box just big enough for it; a character the font lacks is left blank.
     */
    public static function pgm(string $label): string
    {
        $characters = str_split($label);

        $width = max(0, count($characters) * self::Advance - 1) + 2 * self::Padding;

        $height = self::GlyphHeight + 2 * self::Padding;

        $pixels = str_repeat("\0", $width * $height);

        foreach ($characters as $position => $character) {
            foreach (self::Font[$character] ?? [] as $row => $bits) {
                foreach (str_split($bits) as $column => $bit) {
                    if ($bit === '1') {
                        $x = self::Padding + $position * self::Advance + $column;

                        $pixels[(self::Padding + $row) * $width + $x] = "\xff";
                    }
                }
            }
        }

        return "P5\n{$width} {$height}\n255\n{$pixels}";
    }
}
