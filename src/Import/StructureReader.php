<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function spl_object_id;

/**
 * Reads the part of a tagged PDF's structure tree an imported page's content belongs to: the
 * elements its parent tree gives its MCIDs, and the elements above them, in the tree's reading
 * order. Types are mapped through the role map to the standard types; a Document element gives
 * way to its kids, as the page joins a document of its own. Content in forms the page draws
 * (MCRs with a /Stm) and annotations are left out.
 *
 * @internal
 */
final class StructureReader
{
    /** The standard structure types of ISO 32000-1, 14.8.4. */
    private const array STANDARD = [
        'Document',
        'Part',
        'Art',
        'Sect',
        'Div',
        'BlockQuote',
        'Caption',
        'TOC',
        'TOCI',
        'Index',
        'NonStruct',
        'Private',
        'H',
        'H1',
        'H2',
        'H3',
        'H4',
        'H5',
        'H6',
        'P',
        'L',
        'LI',
        'Lbl',
        'LBody',
        'Table',
        'TR',
        'TH',
        'TD',
        'THead',
        'TBody',
        'TFoot',
        'Span',
        'Quote',
        'Note',
        'Reference',
        'BibEntry',
        'Code',
        'Link',
        'Annot',
        'Ruby',
        'RB',
        'RT',
        'RP',
        'Warichu',
        'WT',
        'WP',
        'Figure',
        'Formula',
        'Form',
    ];

    /** The entries of an element that come with its copy. */
    private const array COPIED = ['Alt', 'ActualText', 'Lang', 'E', 'T', 'A'];

    /** @var array<int, Dictionary> the elements owning the page's MCIDs, by MCID */
    private array $owners = [];

    /** @var array<int, true> the elements the page's content belongs to, and those above them */
    private array $relevant = [];

    /** @var array<int, true> the elements already read, so a cycle ends */
    private array $seen = [];

    private ?Dictionary $roleMap = null;
    /** The page's /StructParents key, which marks a page as the one being read. */
    private int $key = -1;

    public function __construct(
        private readonly PdfReader $reader,
    ) {}

    /**
     * The structure a page's content belongs to, under a node standing for the page; null for a
     * page of an untagged file, or one whose content belongs to no element.
     *
     * @throws ParseException
     */
    public function read(PdfPage $page): ?StructureNode
    {
        $root = $this->reader->dict($this->reader->catalog(), 'StructTreeRoot');
        $key  = $this->reader->get($page->dictionary(), 'StructParents');
        $tree = null === $root ? null : $this->reader->dict($root, 'ParentTree');
        if (null === $root || null === $tree || ! is_int($key)) {
            return null;
        }
        $this->key      = $key;
        $this->roleMap  = $this->reader->dict($root, 'RoleMap');
        $this->owners   = [];
        $this->relevant = [];
        $this->seen     = [];
        $owned          = $this->reader->resolve($this->lookup($tree, $key, 0));
        foreach (is_array($owned) ? $owned : [] as $mcid => $element) {
            $element = $this->reader->resolve($element);
            if (is_int($mcid) && $element instanceof Dictionary) {
                $this->owners[$mcid] = $element;
                $this->markAbove($element);
            }
        }
        if ([] === $this->owners) {
            return null;
        }
        $kids = $this->kids($root, null, 0);

        return [] === $kids ? null : new StructureNode('', new Dictionary(), $kids);
    }

    /**
     * A content stream of the file with the MCIDs taken out, claiming no element.
     *
     * @throws ParseException
     */
    public function withoutMcids(string $content): string
    {
        return McidRemover::remove($content, $this->reader->limits()->maxObjectNesting);
    }

    /**
     * Whether a number tree node's limits take in a key; a node without limits may hold any.
     *
     * @throws ParseException
     */
    private function covers(Dictionary $node, int $key): bool
    {
        $limits = $this->reader->get($node, 'Limits');
        if (! is_array($limits)) {
            return true;
        }
        $low  = $this->reader->resolve($limits[0] ?? null);
        $high = $this->reader->resolve($limits[1] ?? null);

        return is_int($low) && is_int($high) && $low <= $key && $key <= $high;
    }

    /**
     * Whether an MCID on a page is the page's content, given to the element by the parent tree.
     *
     * @throws ParseException
     */
    private function isOwnContent(Dictionary $element, ?Dictionary $pg, int $mcid): bool
    {
        return (
            null !== $pg
                && $this->reader->get($pg, 'StructParents') === $this->key
                && ($this->owners[$mcid] ?? null)
                === $element
        );
    }

    /**
     * What a kid dictionary contributes: an MCID of a marked-content reference on the page, the
     * node of an element the page's content belongs to (or its kids, for a Document), or nothing.
     *
     * @return list<StructureNode|int>
     *
     * @throws ParseException
     */
    private function kid(Dictionary $parent, Dictionary $kid, ?Dictionary $pg, int $depth): array
    {
        $type = $this->reader->get($kid, 'Type');
        $type = $type instanceof Name ? $type->value : null;
        if ('MCR' === $type) {
            $mcid = $this->reader->get($kid, 'MCID');
            $on   = $this->reader->dict($kid, 'Pg') ?? $pg;

            return is_int($mcid) && ! $kid->has('Stm') && $this->isOwnContent($parent, $on, $mcid) ? [$mcid] : [];
        }
        $id = spl_object_id($kid);
        if ('OBJR' === $type || ! array_key_exists($id, $this->relevant) || array_key_exists($id, $this->seen)) {
            return [];
        }
        $this->seen[$id] = true;
        $kids            = $this->kids($kid, $this->reader->dict($kid, 'Pg') ?? $pg, $depth + 1);
        $role            = $this->standardType($kid);
        if ('Document' === $role) {
            return $kids;
        }
        $entries = new Dictionary();
        foreach (self::COPIED as $key) {
            $entries->set($key, $kid->get($key));
        }

        return [new StructureNode($role, $entries, $kids)];
    }

    /**
     * The kids of an element (or the tree's root) the page's content belongs to: its MCIDs on
     * the page, and the elements under it that hold some, as nodes.
     *
     * @return list<StructureNode|int>
     *
     * @throws ParseException
     */
    private function kids(Dictionary $parent, ?Dictionary $pg, int $depth): array
    {
        if ($depth > $this->reader->limits()->maxObjectNesting) {
            return [];
        }
        $k    = $parent->get('K');
        $list = is_array($k) ? $k : [$k];
        $out  = [];
        foreach ($list as $kid) {
            if (is_int($kid)) {
                if ($this->isOwnContent($parent, $pg, $kid)) {
                    $out[] = $kid;
                }

                continue;
            }
            $kid = $this->reader->resolve($kid);
            if (! $kid instanceof Dictionary) {
                continue;
            }
            foreach ($this->kid($parent, $kid, $pg, $depth) as $node) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * The value under a key of a number tree, looked up through its kids by their limits.
     *
     * @throws ParseException
     */
    private function lookup(Dictionary $node, int $key, int $depth): mixed
    {
        $nums = $this->reader->get($node, 'Nums');
        if (is_array($nums)) {
            for ($i = 0; ($i + 1) < count($nums); $i += 2) {
                if ($this->reader->resolve($nums[$i] ?? null) === $key) {
                    return $nums[$i + 1] ?? null;
                }
            }
        }
        $kids = $this->reader->get($node, 'Kids');
        if (! is_array($kids) || $depth > $this->reader->limits()->maxObjectNesting) {
            return null;
        }
        foreach ($kids as $kid) {
            $kid = $this->reader->resolve($kid);
            if (! $kid instanceof Dictionary || ! $this->covers($kid, $key)) {
                continue;
            }
            $found = $this->lookup($kid, $key, $depth + 1);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Marks an element and those above it, up to the tree's root, as ones the page's content
     * belongs to.
     *
     * @throws ParseException
     */
    private function markAbove(Dictionary $element): void
    {
        $depth = 0;
        while (
            $element instanceof Dictionary
            && $element->has('S')
            && $depth++ <= $this->reader->limits()->maxObjectNesting
        ) {
            $id = spl_object_id($element);
            if (array_key_exists($id, $this->relevant)) {
                return;
            }
            $this->relevant[$id] = true;
            $element             = $this->reader->resolve($element->get('P'));
        }
    }

    /**
     * An element's standard structure type: its /S, mapped through the role map until a standard
     * type; Div for a type that maps to none.
     *
     * @throws ParseException
     */
    private function standardType(Dictionary $element): string
    {
        $type = $this->reader->get($element, 'S');
        for ($depth = 0; $type instanceof Name && $depth < 16; ++$depth) {
            if (in_array($type->value, self::STANDARD, strict: true)) {
                return $type->value;
            }
            $type = null === $this->roleMap ? null : $this->reader->get($this->roleMap, $type->value);
        }

        return 'Div';
    }
}
