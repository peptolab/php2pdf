<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use function array_key_exists;
use function explode;
use function preg_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * CSS declarations by lower-case property name: the normal ones, and those marked !important,
 * which the cascade applies after every normal one.
 *
 * @internal
 */
final readonly class Declarations
{
    /**
     * @param array<string, string> $normal
     * @param array<string, string> $important
     */
    public function __construct(
        public array $normal = [],
        public array $important = [],
    ) {}

    /** Parses "name: value; name: value !important". */
    public static function parse(string $text): self
    {
        $normal    = [];
        $important = [];
        foreach (explode(';', $text) as $declaration) {
            $colon = strpos($declaration, needle: ':');
            if (false === $colon) {
                continue;
            }
            $name  = strtolower(trim(substr($declaration, offset: 0, length: $colon)));
            $value = trim(substr($declaration, offset: $colon + 1));
            $plain = trim(preg_replace('/!\s*important$/i', replacement: '', subject: $value) ?? $value);
            if ('' === $name || '' === $plain) {
                continue;
            }
            if ($plain !== $value) {
                $important[$name] = $plain;
                unset($normal[$name]);
                continue;
            }
            if (! array_key_exists($name, $important)) {
                $normal[$name] = $plain;
            }
        }

        return new self($normal, $important);
    }

    public function isEmpty(): bool
    {
        return [] === $this->normal && [] === $this->important;
    }

    /** These declarations with another's laid over them, each kind over its own kind. */
    public function then(self $other): self
    {
        return new self([...$this->normal, ...$other->normal], [...$this->important, ...$other->important]);
    }
}
