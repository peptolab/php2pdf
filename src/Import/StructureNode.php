<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;

/**
 * An element of an imported page's structure, as far as the page's content belongs to it: its
 * standard structure type, the entries it brings (as the source has them, not yet copied), and
 * its kids in reading order, each an element or an MCID of the page's content.
 *
 * @internal
 */
final readonly class StructureNode
{
    /** @param list<StructureNode|int> $kids */
    public function __construct(
        public string $type,
        public Dictionary $entries,
        public array $kids,
    ) {}
}
