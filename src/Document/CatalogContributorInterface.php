<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Exception\ParseException;

/**
 * Something that adds entries to the document catalog when the document closes: navigation,
 * optional content, metadata, output intents. A new part of the catalog is a new contributor.
 *
 * @internal
 */
interface CatalogContributorInterface
{
    /**
     * @param list<Reference> $pageRefs the pages, in order
     *
     * @throws ParseException when imported content it lists cannot be read
     */
    public function contribute(Dictionary $catalog, array $pageRefs): void;
}
