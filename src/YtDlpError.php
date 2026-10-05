<?php

declare(strict_types=1);

namespace LoomContext;

enum YtDlpError: string
{
    case Password = 'password';
    case Network = 'network';
    case Unavailable = 'unavailable';
    case Private = 'private';
    case Outdated = 'outdated';
    case Unknown = 'unknown';

    private const NetworkSigns = [
        'tunnel connection failed',
        'proxyerror',
        'unable to connect',
        'name or service not known',
        'connection refused',
        'timed out',
        'network is unreachable',
    ];

    private const PrivateSigns = [
        'private',
        'login',
        'sign in',
        '403',
        'unauthorized',
        'not available',
        'members only',
        'access denied',
        'permission',
    ];

    private const OutdatedSigns = [
        'graphql',
        'unsupported url',
        'unable to extract',
        '400',
        'please report this issue',
    ];

    public static function fromStderr(string $stderr): self
    {
        $text = strtolower($stderr);

        if (str_contains($text, 'password')) {
            return self::Password;
        }

        if (self::mentions($text, self::NetworkSigns)) {
            return self::Network;
        }

        // What yt-dlp says for a private, deleted, or mistyped Loom, worded as a bug to report. It has to be
        // caught before the outdated signs or the auth ladder is never climbed.
        if (str_contains($text, 'no video formats found')) {
            return self::Unavailable;
        }

        if (self::mentions($text, self::PrivateSigns)) {
            return self::Private;
        }

        if (self::mentions($text, self::OutdatedSigns)) {
            return self::Outdated;
        }

        return self::Unknown;
    }

    /**
     * @param  list<string>  $signs
     */
    private static function mentions(string $text, array $signs): bool
    {
        foreach ($signs as $sign) {
            if (str_contains($text, $sign)) {
                return true;
            }
        }

        return false;
    }
}
