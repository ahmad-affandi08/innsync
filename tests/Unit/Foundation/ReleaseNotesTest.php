<?php

declare(strict_types=1);

namespace Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;

/** NFR-14: every release has change notes, and the displayed version is well formed. */
final class ReleaseNotesTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    private static function read(string $file): string
    {
        $contents = file_get_contents(self::ROOT.'/'.$file);
        self::assertNotFalse($contents, "{$file} must exist");

        return $contents;
    }

    private static function configuredVersion(): string
    {
        preg_match('/^APP_VERSION=(\S+)$/m', self::read('.env.example'), $matches);
        self::assertArrayHasKey(1, $matches, '.env.example must define APP_VERSION');

        return $matches[1];
    }

    public function test_the_application_version_is_semantic(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/D',
            self::configuredVersion(),
        );
    }

    public function test_the_changelog_keeps_an_unreleased_section(): void
    {
        self::assertStringContainsString("\n## [Unreleased]\n", self::read('CHANGELOG.md'));
    }

    public function test_a_released_version_has_its_own_notes(): void
    {
        $version = self::configuredVersion();

        if (str_contains($version, '-')) {
            $this->markTestSkipped("{$version} is a pre-release; notes live under [Unreleased].");
        }

        self::assertMatchesRegularExpression(
            '/^## \['.preg_quote($version, '/').'\] - \d{4}-\d{2}-\d{2}$/m',
            self::read('CHANGELOG.md'),
            "CHANGELOG.md needs a '## [{$version}] - YYYY-MM-DD' section before releasing.",
        );
    }
}
