<?php

namespace App\Utils;

use RuntimeException;
use ZipArchive;

/**
 * Reject unsafe ZIP structures before unpacking admin-uploaded themes/plugins.
 * This is defense in depth; plugin/theme uploads still require trusted admins.
 */
final class ArchiveSafety
{
    private const MAX_FILES = 2000;
    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

    public static function validate(ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_FILES) {
            throw new RuntimeException('Archive contains too many entries');
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $info = $zip->statIndex($i);
            $name = $info['name'] ?? null;
            if (!is_string($name) || $name === '' || str_contains($name, "\0")
                || str_contains($name, '\\')
                || str_starts_with($name, '/')
                || preg_match('/\A[A-Za-z]:/', $name)) {
                throw new RuntimeException('Unsafe archive entry path');
            }

            foreach (explode('/', trim($name, '/')) as $component) {
                if ($component === '' || $component === '.' || $component === '..') {
                    throw new RuntimeException('Unsafe archive entry path');
                }
            }

            $size = $info['size'] ?? -1;
            if (!is_int($size) || $size < 0) {
                throw new RuntimeException('Invalid archive entry size');
            }
            $total += $size;
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('Archive exceeds allowed size');
            }

            // A ZIP entry flagged as a UNIX symlink must not be extracted.
            if ($zip->getExternalAttributesIndex($i, $operatingSystem, $attributes)
                && $operatingSystem === ZipArchive::OPSYS_UNIX
                && (($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('Archive symlinks are not allowed');
            }
        }
    }
}
