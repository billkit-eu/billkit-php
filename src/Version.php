<?php

declare(strict_types=1);

namespace BillKit;

/**
 * Single source of truth for the SDK version.
 *
 * The publish workflow (``sdk-php-publish.yml``) asserts the pushed
 * ``sdk-php-vX.Y.Z`` tag matches ``self::VERSION`` before releasing, so
 * this constant and the git tag never drift.
 */
final class Version
{
    public const VERSION = '0.1.0';

    public static function userAgent(): string
    {
        return 'billkit-php/' . self::VERSION;
    }
}
