<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Closure;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\TextString;
use Php2Pdf\Core\Writer;
use Php2Pdf\Navigation\Destination;

use function count;
use function is_int;

/**
 * The document outline: top-level bookmarks and their trees, written when the document closes.
 *
 * @api
 */
final class Outline
{
    /** @var list<Bookmark> */
    private array $roots = [];

    /** The /Count of an item: descendants, negative when the item is closed. */
    private static function countOf(Bookmark $item): ?int
    {
        $count = $item->descendantCount();
        if (0 === $count) {
            return null;
        }

        return $item->open ? $count : -$count;
    }

    /** Adds a top-level bookmark; a page number is shorthand for Destination::page(). */
    public function add(string $title, Destination|int|null $target = null, bool $open = true): Bookmark
    {
        $destination   = is_int($target) ? Destination::page($target) : $target;
        $bookmark      = new Bookmark($title, $destination, null, $open);
        $this->roots[] = $bookmark;

        return $bookmark;
    }

    public function isEmpty(): bool
    {
        return [] === $this->roots;
    }

    /** @return list<Bookmark> */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * Writes the outline tree and returns the reference of its root dictionary.
     *
     * @internal
     *
     * @param Closure(Destination): (list<mixed>|string) $resolve turns a destination into its PDF form
     */
    public function write(Writer $writer, Closure $resolve): Reference
    {
        $root = $writer->allocate();
        $refs = $this->writeLevel($writer, $resolve, $this->roots, $root);
        $writer->write($root, Dictionary::of([
            'Type'  => new Name('Outlines'),
            'First' => $refs[0] ?? null,
            'Last'  => $refs[count($refs) - 1] ?? null,
            'Count' => $this->total(),
        ]));

        return $root;
    }

    private function total(): int
    {
        $count = 0;
        foreach ($this->roots as $root) {
            $count += 1 + $root->descendantCount();
        }

        return $count;
    }

    /**
     * @param Closure(Destination): (list<mixed>|string) $resolve
     * @param list<Bookmark>                             $items
     *
     * @return list<Reference>
     */
    private function writeLevel(Writer $writer, Closure $resolve, array $items, Reference $parent): array
    {
        $pairs = [];
        foreach ($items as $item) {
            $pairs[] = [$item, $writer->allocate()];
        }
        $refs = [];
        foreach ($pairs as $i => [$item, $ref]) {
            $children = $this->writeLevel($writer, $resolve, $item->children(), $ref);
            $writer->write($ref, Dictionary::of([
                'Title'  => TextString::encode($item->title),
                'Parent' => $parent,
                'Prev'   => ($pairs[$i - 1] ?? null)[1] ?? null,
                'Next'   => ($pairs[$i + 1] ?? null)[1] ?? null,
                'First'  => $children[0] ?? null,
                'Last'   => $children[count($children) - 1] ?? null,
                'Count'  => self::countOf($item),
                'Dest'   => null === $item->destination ? null : $resolve($item->destination),
            ]));
            $refs[] = $ref;
        }

        return $refs;
    }
}
