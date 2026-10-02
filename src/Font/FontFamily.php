<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function sprintf;

/**
 * The faces of one family found in the registry's search paths.
 *
 * @api
 */
final class FontFamily
{
    /** @param list<FontInfo> $faces */
    public function __construct(
        private readonly FontRegistry $registry,
        public readonly string $name,
        private readonly array $faces,
    ) {}

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function bold(): Font
    {
        return $this->style(FontStyle::Bold);
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function boldItalic(): Font
    {
        return $this->style(FontStyle::BoldItalic);
    }

    /** @return list<FontInfo> */
    public function faces(): array
    {
        return $this->faces;
    }

    public function has(FontStyle $style): bool
    {
        return null !== $this->face($style);
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function italic(): Font
    {
        return $this->style(FontStyle::Italic);
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function regular(): Font
    {
        return $this->style(FontStyle::Regular);
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function style(FontStyle $style): Font
    {
        $face = $this->face($style) ?? throw new PdfException(sprintf(
            'Family "%s" has no %s face.',
            $this->name,
            $style->name,
        ));

        return $this->registry->load($face->path, $face->faceIndex);
    }

    private function face(FontStyle $style): ?FontInfo
    {
        foreach ($this->faces as $face) {
            if ($face->style === $style) {
                return $face;
            }
        }

        return null;
    }
}
