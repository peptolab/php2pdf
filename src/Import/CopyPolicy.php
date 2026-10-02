<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;

use function in_array;

/**
 * Which source objects a copy follows references to. By default it does not follow pages or
 * page-tree nodes: an imported page is copied as a form, and a reference back to a page (an
 * annotation's /P, a node's /Parent) would otherwise copy every page of the source.
 *
 * @internal
 */
final readonly class CopyPolicy
{
    /** @param list<string> $skippedTypes the /Type values of objects not copied */
    public function __construct(
        private array $skippedTypes = ['Page', 'Pages'],
    ) {}

    /** Whether a source object is copied when something refers to it. */
    public function follows(mixed $object): bool
    {
        $dict = $object instanceof Stream ? $object->dictionary : $object;
        if (! $dict instanceof Dictionary) {
            return true;
        }
        $type = $dict->get('Type');

        return ! $type instanceof Name || ! in_array($type->value, $this->skippedTypes, strict: true);
    }
}
