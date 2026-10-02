<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\TextString;
use Php2Pdf\Core\Writer;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Navigation\Destination;

use function array_key_exists;
use function count;
use function is_string;
use function sprintf;
use function strcmp;
use function usort;

/**
 * A document's links, named destinations and outline, written when the document closes and
 * every page they may point at exists.
 *
 * @internal
 */
final class Navigation
{
    public readonly Outline $outline;

    /** @var array<string, Destination> */
    private array $destinations = [];

    /** @var list<array{0: Reference, 1: Box, 2: Destination|string, 3: string|null}> */
    private array $links = [];

    public function __construct(
        private readonly Writer $writer,
    ) {
        $this->outline = new Outline();
    }

    public function addDestination(string $name, Destination $destination): void
    {
        $this->destinations[$name] = $destination;
    }

    /** Allocates a link annotation; it is written at close. */
    public function addLink(Box $area, Destination|string $target, ?string $tooltip = null): Reference
    {
        $ref           = $this->writer->allocate();
        $this->links[] = [$ref, $area, $target, $tooltip];

        return $ref;
    }

    /**
     * Writes link annotations, named destinations and the outline, and adds them to the catalog.
     *
     * @param list<Reference> $pageRefs
     */
    public function write(Dictionary $catalog, array $pageRefs): void
    {
        $resolve =
            /** @return list<mixed>|string */
            fn(Destination $destination): array|string => $this->resolve($destination, $pageRefs);
        foreach ($this->links as [$ref, $area, $target, $tooltip]) {
            $annotation = Dictionary::of([
                'Type'     => new Name('Annot'),
                'Subtype'  => new Name('Link'),
                'Rect'     => $area->toRect(),
                'Border'   => [0, 0, 0],
                'Contents' => null === $tooltip ? null : TextString::encode($tooltip),
            ]);
            if ($target instanceof Destination) {
                $annotation->set('Dest', $resolve($target));
            }
            if (is_string($target)) {
                $annotation->set('A', Dictionary::of(['S' => new Name('URI'), 'URI' => $target]));
            }
            $this->writer->write($ref, $annotation);
        }
        if ([] !== $this->destinations) {
            $catalog->set('Names', Dictionary::of(['Dests' => $this->writer->add($this->nameTree($resolve))]));
        }
        if (! $this->outline->isEmpty()) {
            $catalog->set('Outlines', $this->outline->write($this->writer, $resolve));
            $catalog->set('PageMode', new Name('UseOutlines'));
        }
    }

    /**
     * The named destinations as a name tree: one node listing names and destinations in byte
     * order, which is how links and bookmarks that refer to a destination by name find it.
     *
     * @param callable(Destination): (list<mixed>|string) $resolve
     */
    private function nameTree(callable $resolve): Dictionary
    {
        $pairs = [];
        foreach ($this->destinations as $name => $destination) {
            $pairs[] = ["{$name}", $resolve($destination)];
        }
        usort(
            $pairs,
            /**
             * @param array{0: string, 1: mixed} $a
             * @param array{0: string, 1: mixed} $b
             */
            static fn(array $a, array $b): int => strcmp($a[0], $b[0]),
        );
        $entries = [];
        foreach ($pairs as [$name, $destination]) {
            $entries[] = $name;
            $entries[] = $destination;
        }

        return Dictionary::of([
            'Limits' => [$pairs[0][0] ?? '', $pairs[count($pairs) - 1][0] ?? ''],
            'Names'  => $entries,
        ]);
    }

    /**
     * The PDF form of a destination, with page numbers resolved to page references.
     *
     * @param list<Reference> $pageRefs
     *
     * @return list<mixed>|string
     */
    private function resolve(Destination $destination, array $pageRefs): array|string
    {
        if (null !== $destination->name && ! array_key_exists($destination->name, $this->destinations)) {
            throw new InvalidArgumentException(sprintf('Named destination "%s" is not defined.', $destination->name));
        }
        $pageRef = null;
        if (null !== $destination->page) {
            $pageRef = $pageRefs[$destination->page - 1] ?? throw new InvalidArgumentException(sprintf(
                'Destination page %d does not exist.',
                $destination->page,
            ));
        }

        return $destination->toPdf($pageRef);
    }
}
