<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** English is the source language: Indonesian must define every key with the same placeholders. */
final class LanguageFilesTest extends TestCase
{
    private const LANG = __DIR__.'/../../../../lang';

    /** @return iterable<string, array{0: string}> */
    public static function files(): iterable
    {
        foreach (glob(self::LANG.'/en/*.php') ?: [] as $path) {
            yield basename($path) => [basename($path)];
        }
    }

    /** @return array<string, string> */
    private static function flatten(string $locale, string $file): array
    {
        $path = self::LANG."/{$locale}/{$file}";
        self::assertFileExists($path);

        $out = [];
        $walk = static function (array $items, string $prefix) use (&$walk, &$out): void {
            foreach ($items as $key => $value) {
                is_array($value) ? $walk($value, $prefix.$key.'.') : $out[$prefix.$key] = (string) $value;
            }
        };
        $walk(require $path, '');

        return $out;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/:[a-z_]+/i', $text, $matches);
        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    }

    #[DataProvider('files')]
    public function test_indonesian_has_exactly_the_english_keys(string $file): void
    {
        $en = array_keys(self::flatten('en', $file));
        $id = array_keys(self::flatten('id', $file));
        sort($en);
        sort($id);

        self::assertSame($en, $id, "Key mismatch in {$file}");
    }

    #[DataProvider('files')]
    public function test_placeholders_match_between_languages(string $file): void
    {
        $en = self::flatten('en', $file);
        $id = self::flatten('id', $file);

        foreach ($en as $key => $text) {
            self::assertSame(
                self::placeholders($text),
                self::placeholders($id[$key] ?? ''),
                "{$file}: {$key}",
            );
            self::assertNotSame('', trim($id[$key] ?? ''), "{$file}: {$key} is empty");
        }
    }

    public function test_error_messages_expose_no_internals(): void
    {
        foreach (['en', 'id'] as $locale) {
            foreach (self::flatten($locale, 'errors.php') as $key => $text) {
                self::assertDoesNotMatchRegularExpression('/SQLSTATE|Exception|\.php|stack/i', $text, "{$locale}:{$key}");
            }
        }
    }
}
