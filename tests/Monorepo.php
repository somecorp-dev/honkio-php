<?php

declare(strict_types=1);

namespace Honkio\Tests;

/**
 * Some tests read the HonkIO monorepo (the OpenAPI snapshot, the docs samples,
 * the API source). A mirror clone of sdk/php has no monorepo around it, so
 * those tests skip there; inside the monorepo a moved file still fails hard.
 */
final class Monorepo
{
    public static function present(): bool
    {
        return is_dir(__DIR__ . '/../../../api') && is_dir(__DIR__ . '/../../../web');
    }

    public static function skipReason(): string
    {
        return 'needs the HonkIO monorepo (../../../api and ../../../web); this is a standalone copy of sdk/php';
    }
}
