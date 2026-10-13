<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Canvas\Tagger;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Structure\StructureElement;
use Php2Pdf\Structure\StructureRole;
use Php2Pdf\Text\Style\TextStyle;

use function count;
use function in_array;
use function spl_object_id;

/**
 * The elements a textflow's text is tagged as on a tagged page: one for each paragraph, in the
 * role its style gives or P, and one for each piece of linked text within it. Both are kept from
 * one box to the next, so a paragraph or a link broken across lines, columns or pages stays one
 * element.
 *
 * @internal
 */
final class FlowTags
{
    /** @var array<int, StructureElement> the elements of the paragraphs drawn, by index */
    private array $paragraphs = [];

    /** The link whose text ended the last line drawn, its key and its paragraph, which the next line may continue. */
    private ?StructureElement $link = null;

    private ?string $linkKey       = null;
    private int     $linkParagraph = -1;

    /** The element being drawn when the flow was last drawn, which its new paragraphs go under. */
    private ?StructureElement $parent = null;

    /** Whether the flow is being drawn in an artifact, so its text is not tagged. */
    private bool $artifact = false;

    /** @var array<int, StructureElement> the lists the paragraphs are in, by number */
    private array $lists = [];

    /** @var array<int, array{0: StructureElement, 1: StructureElement}> the items, by number: LI and LBody */
    private array $items = [];

    /** @var array<int, StructureElement> the labels of the items, by their LI elements' object ids */
    private array $labels = [];

    /** @var list<StructureElement> the elements of the link areas drawn since drawnLinks() was last asked */
    private array $drawn = [];

    /** @param list<Paragraph> $sources the flow's paragraphs */
    public function __construct(
        private readonly array $sources,
    ) {}

    /**
     * A new item of a list: its LI element, and the LBody in it its paragraphs and nested lists go in.
     *
     * @return array{0: StructureElement, 1: StructureElement}
     */
    private static function newItem(Tagger $tagger, StructureElement $list): array
    {
        $item = $tagger->element(StructureRole::ListItem, parent: $list);

        return [$item, $tagger->element(StructureRole::ListBody, parent: $item)];
    }

    /**
     * The elements of the link areas drawn since this was last asked, in the order markedAreas()
     * finds them.
     *
     * @return list<StructureElement>
     */
    public function drawnLinks(): array
    {
        $links       = $this->drawn;
        $this->drawn = [];

        return $links;
    }

    /** Starts drawing the flow into what is being drawn on a page. */
    public function enter(Tagger $tagger): void
    {
        $this->parent   = $tagger->current();
        $this->artifact = $tagger->isArtifact();
    }

    /**
     * The element a list item's marker is drawn as: its label, first in the item; none when the
     * flow is drawn in an artifact.
     */
    public function label(Tagger $tagger, ListPosition $list): ?StructureElement
    {
        if ($this->artifact) {
            return null;
        }
        [$item] = $this->item($tagger, $list);

        return $this->labels[spl_object_id($item)] ??= $tagger->element(StructureRole::Label, parent: $item);
    }

    /**
     * The elements of a line's segments, by index: its paragraph's, or for linked text a link's
     * under it. A link run that starts the line continues the link that ended the line before.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: TextStyle, 5: list<int>}> $runs
     *        the line's link runs, each with the indices of its segments last
     *
     * @return array<int, StructureElement>
     */
    public function line(Tagger $tagger, PlacedLine $placed, array $runs): array
    {
        $paragraph = $this->paragraph($tagger, $placed->paragraph);
        if (null === $paragraph) {
            return [];
        }
        $elements = [];
        foreach ($placed->line->segments as $index => $_) {
            $elements[$index] = $paragraph;
        }
        $ending = null;
        $last   = count($placed->line->segments) - 1;
        foreach ($runs as [, , , , $style, $indices]) {
            $key     = LinePainter::linkKey($style);
            $element = ($indices[0] ?? -1) === 0
            && $key === $this->linkKey
            && $placed->paragraph === $this->linkParagraph
                ? $this->link
                : null;
            $element       ??= $tagger->element(StructureRole::Link, parent: $paragraph);
            $this->drawn[] = $element;
            foreach ($indices as $index) {
                $elements[$index] = $element;
            }
            $ending = in_array($last, $indices, strict: true) ? [$element, $key] : null;
        }
        [$this->link, $this->linkKey] = $ending ?? [null, null];
        $this->linkParagraph = $placed->paragraph;

        return $elements;
    }

    /**
     * The innermost list item a paragraph is in, as its LI and LBody elements, each list and item
     * on the way made the first time: a list under the element being drawn when the flow was, or
     * nested in the body of the item it is in.
     *
     * @return array{0: StructureElement, 1: StructureElement}
     */
    private function item(Tagger $tagger, ListPosition $list): array
    {
        $parent = $this->parent;
        $item   = null;
        foreach ($list->path as [$number, $itemNumber, $marker]) {
            $this->lists[$number] ??= $tagger->element(
                StructureRole::List,
                attributes: Dictionary::of([
                    'O'             => new Name('List'),
                    'ListNumbering' => new Name($marker->numbering()),
                ]),
                parent: $parent,
            );
            $this->items[$itemNumber] ??= self::newItem($tagger, $this->lists[$number]);
            $item                     = $this->items[$itemNumber];
            $parent                   = $item[1];
        }

        return $item;
    }

    /** A paragraph's element, made under the element being drawn the first time it is drawn. */
    private function paragraph(Tagger $tagger, int $index): ?StructureElement
    {
        if ($this->artifact) {
            return null;
        }

        return $this->paragraphs[$index] ??= $tagger->element(
            ($this->sources[$index] ?? null)?->style->role ?? StructureRole::Paragraph,
            parent: $this->paragraphParent($tagger, ($this->sources[$index] ?? null)?->list),
        );
    }

    /** What a paragraph's element goes under: its list item's body, or the element the flow was drawn in. */
    private function paragraphParent(Tagger $tagger, ?ListPosition $list): ?StructureElement
    {
        return null === $list ? $this->parent : $this->item($tagger, $list)[1];
    }
}
