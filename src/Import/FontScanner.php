<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function array_values;
use function in_array;
use function is_array;
use function spl_object_id;

/**
 * The fonts an imported page uses whose programs are not embedded, as PDF/X and PDF/A refuse:
 * those of its resources, and of the forms, patterns and Type 3 glyphs it draws, whatever their
 * depth. A Type 3 font draws its glyphs itself, so it needs no program.
 *
 * @internal
 */
final class FontScanner
{
    /** @var list<string> the names of the fonts found without programs */
    private array $missing = [];

    /** @var array<int, true> resources and streams already scanned */
    private array $seen = [];

    public function __construct(
        private readonly PdfReader $reader,
    ) {}

    /**
     * The base font names of the page's fonts without embedded programs.
     *
     * @return list<string>
     *
     * @throws ParseException
     */
    public function scan(PdfPage $page): array
    {
        $this->missing = [];
        $this->seen    = [];
        $resources     = $page->resources();
        if (null !== $resources) {
            $this->resources($resources, 0);
        }

        return $this->missing;
    }

    /**
     * Scans a form or a tiling pattern for the fonts its resources hold.
     *
     * @throws ParseException
     */
    private function drawing(mixed $object, int $depth): void
    {
        $object = $this->reader->resolve($object);
        if (! $object instanceof Stream) {
            return;
        }
        $resources = $this->reader->dict($object->dictionary, 'Resources');
        if (null !== $resources) {
            $this->resources($resources, $depth + 1);
        }
    }

    /**
     * The values of a resource sub-dictionary, unresolved.
     *
     * @return list<mixed>
     *
     * @throws ParseException
     */
    private function entries(Dictionary $resources, string $key): array
    {
        $dict = $this->reader->dict($resources, $key);

        return null === $dict ? [] : array_values($dict->all());
    }

    /**
     * Records a font without an embedded program, and scans a Type 3 font's own resources.
     *
     * @throws ParseException
     */
    private function font(mixed $font, int $depth): void
    {
        $font = $this->reader->resolve($font);
        if (! $font instanceof Dictionary) {
            return;
        }
        $subtype = $this->reader->get($font, 'Subtype');
        if ($subtype instanceof Name && 'Type3' === $subtype->value) {
            $resources = $this->reader->dict($font, 'Resources');
            if (null !== $resources) {
                $this->resources($resources, $depth + 1);
            }

            return;
        }
        $described   = $font;
        $descendants = $this->reader->get($font, 'DescendantFonts');
        if (is_array($descendants)) {
            $descendant = $this->reader->resolve($descendants[0] ?? null);
            $described  = $descendant instanceof Dictionary ? $descendant : $font;
        }
        $descriptor = $this->reader->dict($described, 'FontDescriptor');
        if (
            null !== $descriptor
            && (
                $descriptor->has('FontFile')
                || $descriptor->has('FontFile2')
                || $descriptor->has('FontFile3')
            )
        ) {
            return;
        }
        $name = $this->reader->get($font, 'BaseFont');
        $name = $name instanceof Name ? $name->value : 'unnamed';
        if (! in_array($name, $this->missing, strict: true)) {
            $this->missing[] = $name;
        }
    }

    /**
     * Scans a resource dictionary and everything it draws.
     *
     * @throws ParseException
     */
    private function resources(Dictionary $resources, int $depth): void
    {
        $id = spl_object_id($resources);
        if (array_key_exists($id, $this->seen) || $depth > $this->reader->limits()->maxObjectNesting) {
            return;
        }
        $this->seen[$id] = true;
        foreach ($this->entries($resources, 'Font') as $font) {
            $this->font($font, $depth);
        }
        foreach ([...$this->entries($resources, 'XObject'), ...$this->entries($resources, 'Pattern')] as $drawing) {
            $this->drawing($drawing, $depth);
        }
    }
}
