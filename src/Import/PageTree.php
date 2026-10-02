<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function array_key_exists;
use function count;
use function is_array;
use function sprintf;

/**
 * A source file's page tree, walked into its pages in document order, each with the attributes it
 * inherits from the nodes above it (resources, media box, crop box and rotation).
 *
 * @internal
 */
final readonly class PageTree
{
    public function __construct(
        private PdfReader $reader,
    ) {}

    /**
     * The attributes a node passes down: those it inherits, with its own over them.
     */
    private static function inherited(Dictionary $node, Dictionary $inherited): Dictionary
    {
        $merged = new Dictionary($inherited->all());
        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $key) {
            if (! $node->has($key)) {
                continue;
            }
            $merged->set($key, $node->get($key));
        }

        return $merged;
    }

    /** A page with the attributes it inherits and does not set itself. */
    private static function page(Dictionary $node, Dictionary $inherited): Dictionary
    {
        $page = new Dictionary($node->all());
        foreach ($inherited->all() as $key => $value) {
            if ($page->has($key)) {
                continue;
            }
            $page->set($key, $value);
        }

        return $page;
    }

    /**
     * The pages under a root node.
     *
     * @return list<Dictionary>
     *
     * @throws PdfException when the document has more pages than the limit allows
     */
    public function pages(Dictionary $root): array
    {
        $pages   = [];
        $visited = [];
        $this->walk($root, new Dictionary(), $pages, $visited, 0);

        return $pages;
    }

    /**
     * A kid of a node, resolved, or null when it is not a dictionary or was walked already.
     *
     * @param array<int, true> $visited
     *
     * @throws ParseException
     */
    private function unvisited(mixed $kid, array &$visited): ?Dictionary
    {
        $id = $kid instanceof Reference ? $kid->id : null;
        if (null !== $id && array_key_exists($id, $visited)) {
            return null;
        }
        $child = $this->reader->resolve($kid);
        if (! $child instanceof Dictionary) {
            return null;
        }
        if (null !== $id) {
            $visited[$id] = true;
        }

        return $child;
    }

    /**
     * Collects the page dictionaries under $node. Every tree node is visited once, however many
     * parents refer to it, so a page tree that shares nodes cannot multiply into millions of pages.
     * A node is a page when its type says so, or, untyped, when it has content and no kids.
     *
     * @param list<Dictionary> $pages
     * @param array<int, true> $visited object numbers of the nodes already walked
     *
     * @throws PdfException when the document has more pages than the limit allows
     */
    private function walk(Dictionary $node, Dictionary $inherited, array &$pages, array &$visited, int $depth): void
    {
        $limits = $this->reader->limits();
        if ($depth > $limits->maxObjectNesting) {
            return;
        }
        $merged = self::inherited($node, $inherited);
        $kids   = $this->reader->get($node, 'Kids');
        $type   = $node->get('Type');
        if (($type instanceof Name && 'Page' === $type->value) || (! is_array($kids) && $node->has('Contents'))) {
            if (count($pages) >= $limits->maxPages) {
                throw new ParseException(sprintf('The PDF has more than %d pages.', $limits->maxPages));
            }
            $pages[] = self::page($node, $merged);

            return;
        }
        foreach (is_array($kids) ? $kids : [] as $kid) {
            $child = $this->unvisited($kid, $visited);
            if (null === $child) {
                continue;
            }
            $this->walk($child, $merged, $pages, $visited, $depth + 1);
        }
    }
}
