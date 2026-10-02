<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

use Php2Pdf\Core\Lru;
use Php2Pdf\Font\TextDirection;
use WeakMap;

/**
 * A paragraph style with every setting resolved: what the style sets, and the default for what it
 * leaves unset. Built once for each style and kept while the style lives, so layout reads settled
 * values rather than falling back to defaults in every place that needs one.
 *
 * @internal
 */
final class ParagraphFormat
{
    /** How many distinct formats are kept to be shared. */
    private const int SHARED = 512;

    /** @var WeakMap<TextStyle, self>|null each style's format */
    private static ?WeakMap $formats = null;

    /** @var Lru<self>|null recent formats by value, shared by the styles that resolve to them */
    private static ?Lru $shared = null;

    /**
     * @param list<TabStop> $tabs
     * @param string|null   $language the language for hyphenation patterns, or none
     */
    private function __construct(
        private readonly ?Alignment $alignment,
        private readonly ?Alignment $lastLineAlignment,
        public readonly float $firstLineIndent,
        public readonly float $leftIndent,
        public readonly float $rightIndent,
        public readonly float $spaceBefore,
        public readonly float $spaceAfter,
        public readonly bool $hyphenate,
        public readonly ?string $language,
        public readonly int $minHyphenatedWordLength,
        public readonly int $hyphenLeftMin,
        public readonly int $hyphenRightMin,
        public readonly array $tabs,
        public readonly float $defaultTabWidth,
        public readonly bool $keepWithNext,
        public readonly bool $keepTogether,
        public readonly int $orphans,
        public readonly int $widows,
        public readonly TextDirection $direction,
        public readonly Composer $composer,
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
            $style->alignment,
            $style->lastLineAlignment,
            $style->firstLineIndent ?? 0.0,
            $style->leftIndent ?? 0.0,
            $style->rightIndent ?? 0.0,
            $style->spaceBefore ?? 0.0,
            $style->spaceAfter ?? 0.0,
            true === $style->hyphenate,
            $style->language,
            $style->minHyphenatedWordLength ?? 6,
            $style->hyphenLeftMin ?? 2,
            $style->hyphenRightMin ?? 2,
            $style->tabs ?? [],
            $style->defaultTabWidth ?? 36.0,
            true === $style->keepWithNext,
            true === $style->keepTogether,
            $style->orphans ?? 2,
            $style->widows ?? 2,
            $style->direction ?? TextDirection::Auto,
            $style->composer
                ?? (Alignment::Justify === $style->alignment ? Composer::Paragraph : Composer::SingleLine),
        );
    }

    private static function start(int $level): Alignment
    {
        return 1 === ($level & 1) ? Alignment::Right : Alignment::Left;
    }

    /** The alignment of the paragraph's lines: as set, or its start side at bidi level $level. */
    public function alignment(int $level): Alignment
    {
        return $this->alignment ?? self::start($level);
    }

    /** The alignment of its last line: a justified paragraph's, as set or its start side. */
    public function lastLineAlignment(int $level): Alignment
    {
        $alignment = $this->alignment($level);

        return Alignment::Justify === $alignment ? $this->lastLineAlignment ?? self::start($level) : $alignment;
    }

    /** The format's values as a key. */
    private function key(): string
    {
        return FormatKey::of([
            $this->alignment,
            $this->lastLineAlignment,
            $this->firstLineIndent,
            $this->leftIndent,
            $this->rightIndent,
            $this->spaceBefore,
            $this->spaceAfter,
            $this->hyphenate,
            $this->language,
            $this->minHyphenatedWordLength,
            $this->hyphenLeftMin,
            $this->hyphenRightMin,
            $this->tabs,
            $this->defaultTabWidth,
            $this->keepWithNext,
            $this->keepTogether,
            $this->orphans,
            $this->widows,
            $this->direction,
            $this->composer,
        ]);
    }
}
