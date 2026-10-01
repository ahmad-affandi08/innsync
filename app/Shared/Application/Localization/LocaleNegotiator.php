<?php

declare(strict_types=1);

namespace App\Shared\Application\Localization;

use InvalidArgumentException;

/**
 * Chooses the UI language from an explicit choice or an `Accept-Language`
 * header. Framework independent: it only knows the supported tags and the
 * default, and never returns an unsupported locale.
 */
final readonly class LocaleNegotiator
{
    /** @param list<string> $supported */
    public function __construct(private array $supported, private string $default)
    {
        if ($supported === [] || ! in_array($default, $supported, true)) {
            throw new InvalidArgumentException('The default locale must be one of the supported locales.');
        }
    }

    public function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, $this->supported, true);
    }

    public function default(): string
    {
        return $this->default;
    }

    /** @return list<string> */
    public function supported(): array
    {
        return $this->supported;
    }

    /** An explicit choice wins; otherwise the best `Accept-Language` match; otherwise the default. */
    public function resolve(?string $chosen, ?string $acceptLanguage): string
    {
        if ($this->isSupported($chosen)) {
            return (string) $chosen;
        }

        return $this->fromAcceptLanguage($acceptLanguage) ?? $this->default;
    }

    public function fromAcceptLanguage(?string $header): ?string
    {
        if ($header === null || trim($header) === '' || strlen($header) > 512) {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $position => $part) {
            $segments = explode(';', trim($part));
            $tag = strtolower(trim($segments[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*(\d(?:\.\d{0,3})?)\s*$/i', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }

            if ($quality <= 0.0) {
                continue;
            }

            $primary = preg_split('/[-_]/', $tag)[0] ?? '';

            if ($this->isSupported($primary)) {
                $candidates[] = [$quality, $position, $primary];
            }
        }

        if ($candidates === []) {
            return null;
        }

        // Highest quality first; the header order breaks ties.
        usort($candidates, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return $candidates[0][2];
    }
}
