<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Localization;

use App\Shared\Application\Localization\LocaleNegotiator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleNegotiatorTest extends TestCase
{
    private function negotiator(string $default = 'en'): LocaleNegotiator
    {
        return new LocaleNegotiator(['id', 'en'], $default);
    }

    /** @return iterable<string, array{0: ?string, 1: ?string}> */
    public static function headers(): iterable
    {
        yield 'primary subtag' => ['id-ID,id;q=0.9,en;q=0.8', 'id'];
        yield 'quality order wins over position' => ['en;q=0.4, id;q=0.9', 'id'];
        yield 'position breaks equal quality' => ['en, id', 'en'];
        yield 'case and underscore' => ['ID_id', 'id'];
        yield 'unsupported only' => ['fr-FR,de;q=0.8', null];
        yield 'wildcard ignored' => ['*', null];
        yield 'zero quality refused' => ['id;q=0, en;q=0.1', 'en'];
        yield 'garbage quality ignored as default 1' => ['id;q=abc', 'id'];
        yield 'empty' => ['', null];
        yield 'missing' => [null, null];
        yield 'oversized header' => [str_repeat('x', 600).',id', null];
    }

    #[DataProvider('headers')]
    public function test_accept_language_negotiation(?string $header, ?string $expected): void
    {
        self::assertSame($expected, $this->negotiator()->fromAcceptLanguage($header));
    }

    public function test_explicit_choice_wins_then_header_then_default(): void
    {
        $negotiator = $this->negotiator('en');

        self::assertSame('id', $negotiator->resolve('id', 'en'));
        self::assertSame('id', $negotiator->resolve('xx', 'id'));
        self::assertSame('en', $negotiator->resolve(null, 'fr'));
        self::assertSame('en', $negotiator->resolve('', null));
    }

    public function test_never_returns_an_unsupported_locale(): void
    {
        foreach (['../etc', 'ID', 'id-ID', "id\n", 'xx'] as $hostile) {
            self::assertFalse($this->negotiator()->isSupported($hostile), $hostile);
            self::assertSame('en', $this->negotiator()->resolve($hostile, null));
        }
    }

    public function test_fails_closed_when_the_default_is_not_supported(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LocaleNegotiator(['id', 'en'], 'fr');
    }
}
