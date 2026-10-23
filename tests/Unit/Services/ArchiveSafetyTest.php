<?php

namespace Tests\Unit\Services;

use App\Services\ThemeService;
use App\Utils\ArchiveSafety;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class ArchiveSafetyTest extends TestCase
{
    private function archiveWith(string $path): array
    {
        $file = tempnam(sys_get_temp_dir(), 'xboard_zip_');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString($path, 'data');
        $zip->close();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file));
        return [$file, $zip];
    }

    public function test_rejects_zip_parent_directory_escapes(): void
    {
        [$file, $zip] = $this->archiveWith('../outside.php');
        try {
            $this->expectException(RuntimeException::class);
            ArchiveSafety::validate($zip);
        } finally {
            $zip->close();
            unlink($file);
        }
    }

    public function test_accepts_normal_nested_archive(): void
    {
        [$file, $zip] = $this->archiveWith('theme/config.json');
        try {
            ArchiveSafety::validate($zip);
            $this->assertTrue(true);
        } finally {
            $zip->close();
            unlink($file);
        }
    }

    public function test_theme_names_cannot_escape_theme_root(): void
    {
        foreach (['../storage', '/tmp', 'a/b', '..', 'theme..evil'] as $name) {
            $this->assertFalse(ThemeService::isValidThemeName($name));
        }
        $this->assertTrue(ThemeService::isValidThemeName('Xboard-v2.1'));
    }
}
