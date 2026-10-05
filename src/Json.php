<?php

declare(strict_types=1);

namespace LoomContext;

class Json
{
    private const Readable = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param  array<mixed>  $data
     */
    public static function encode(array $data): string
    {
        return json_encode($data, self::Readable | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function write(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, self::Readable | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    public static function read(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
