<?php

namespace App\Support;

/**
 * Compares an operating system name against a project's minimum, such as
 * "Windows 7" against "Windows 10". Only the same OS family can be below the
 * minimum: "macOS 14" is never "below" "Windows 10".
 */
class OsRequirement
{
    public static function isBelow(?string $os, ?string $minimum): bool
    {
        $current = self::parse($os);
        $required = self::parse($minimum);

        if ($current === null || $required === null || $current[0] !== $required[0]) {
            return false;
        }

        return version_compare($current[1], $required[1], '<');
    }

    /**
     * @return array{0: string, 1: string}|null family in lower case, and version
     */
    private static function parse(?string $os): ?array
    {
        if ($os !== null && preg_match('/^(.*?)\s*(\d+(?:\.\d+)*)$/', trim($os), $m) && $m[1] !== '') {
            return [strtolower($m[1]), $m[2]];
        }

        return null;
    }
}
