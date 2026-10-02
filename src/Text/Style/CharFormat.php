<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

use Php2Pdf\Core\Lru;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\TextOptions;
use WeakMap;

/**
 * A character style with every setting layout measures by resolved: the size and line height,
 * the shaping and showing options (made once), the horizontal scale and rise, how far its word
 * spaces stretch and shrink, and its fallback fonts. Built once for each style and kept while the
 * style lives.
 *
 * @internal
 */
final class CharFormat
{
    /** How many distinct formats are kept to be shared. */
    private const int SHARED = 512;

    /** @var WeakMap<TextStyle, self>|null each style's format */
    private static ?WeakMap $formats = null;

    /** @var Lru<self>|null recent formats by value, shared by the styles that resolve to them */
    private static ?Lru $shared = null;

    /**
     * @param float                            $stretch how far a word space may stretch, as a fraction of its width
     * @param float                            $shrink  how far it may shrink, as a fraction of its width
     * @param list<FontInterface|string>       $fallback
     */
    private function __construct(
        public readonly float $size,
        public readonly float $lineHeight,
        public readonly TextOptions $textOptions,
        public readonly float $horizontalScale,
        public readonly float $rise,
        public readonly float $stretch,
        public readonly float $shrink,
        public readonly array $fallback,
    ) {}

    /**
     * The format of a style: resolved once for each style, and one object for styles that
     * resolve to the same values, as the cells of a table do.
     */
    public static function of(TextStyle $style): self
    {
        self::$formats ??= new WeakMap();
        $format        = self::$formats[$style] ?? null;
        if (null !== $format) {
            return $format;
        }
        self::$shared ??= new Lru(self::SHARED);
        $resolved     = self::resolve($style);
        $key          = $resolved->key();
        $format       = self::$shared->get($key);
        if (null === $format) {
            $format = $resolved;
            self::$shared->set($key, $format);
        }

        return self::$formats[$style] = $format;
    }

    private static function resolve(TextStyle $style): self
    {
        return new self(
            $style->fontSize(),
            $style->lineHeight(),
            $style->textOptions(),
            $style->horizontalScale ?? 100.0,
            $style->rise ?? 0.0,
            Justification::stretchOf($style->justification),
            Justification::shrinkOf($style->justification),
            $style->fallback ?? [],
        );
    }

    /** The format's values as a key. */
    private function key(): string
    {
        return FormatKey::of([
            $this->size,
            $this->lineHeight,
            $this->textOptions,
            $this->horizontalScale,
            $this->rise,
            $this->stretch,
            $this->shrink,
            $this->fallback,
        ]);
    }
}
