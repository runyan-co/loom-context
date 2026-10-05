<?php

declare(strict_types=1);

namespace LoomContext;

/**
 * One rung of the auth ladder: how yt-dlp is asked to identify itself to Loom.
 */
class Access
{
    public function __construct(
        public string $label,
        public ?string $browser = null,
        public ?string $cookieJar = null,
    ) {}

    public function isAnonymous(): bool
    {
        return $this->browser === null && $this->cookieJar === null;
    }
}
