<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Exception\ExceptionInterface;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\FontStyle;

use function array_key_exists;
use function implode;
use function strtolower;

/**
 * Maps CSS font-family lists and weight/style to embedded fonts from the document's registry,
 * falling back to a configured font for generic families and unknown names.
 *
 * @internal
 */
final class FontResolver
{
    /** @var array<string, FontInterface|null> */
    private array $cache = [];

    public function __construct(
        private readonly SvgDocumentInterface $document,
        private readonly ?FontInterface $fallback,
    ) {}

    /**
     * @param list<string> $families
     *
     * @throws IoException
     * @throws ParseException
     */
    public function resolve(array $families, bool $bold, bool $italic): ?FontInterface
    {
        $style = match (true) {
            $bold && $italic => FontStyle::BoldItalic,
            $bold   => FontStyle::Bold,
            $italic => FontStyle::Italic,
            default => FontStyle::Regular,
        };
        $key = strtolower(implode('|', $families)) . '#' . $style->name;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->lookup($families, $style);
    }

    /**
     * @param list<string> $families
     *
     * @throws IoException
     * @throws ParseException
     */
    private function lookup(array $families, FontStyle $style): ?FontInterface
    {
        $registry = $this->document->fonts();
        foreach ($families as $family) {
            try {
                $found = $registry->family($family);
            } catch (ExceptionInterface) {
                continue;
            }
            if ($found->has($style)) {
                return $found->style($style);
            }
            if ($found->has(FontStyle::Regular)) {
                return $found->regular();
            }
        }

        return $this->fallback;
    }
}
