<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Override;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Objects;
use Php2Pdf\Core\TextString;
use Php2Pdf\Structure\StructureTree;

/**
 * A tagged document's structure tree, and what the catalog says of it: that the document is
 * tagged, its language, and under PDF/UA that viewers show its title.
 *
 * @internal
 */
final readonly class Tagging implements CatalogContributorInterface
{
    public StructureTree $tree;

    public function __construct(
        Objects $objects,
        private Accessibility $accessibility,
    ) {
        $this->tree = new StructureTree($objects);
    }

    #[Override]
    public function contribute(Dictionary $catalog, array $pageRefs): void
    {
        $catalog->set('StructTreeRoot', $this->tree->write());
        $catalog->set('MarkInfo', Dictionary::of(['Marked' => true]));
        $language = $this->accessibility->language;
        if (null !== $language) {
            $catalog->set('Lang', TextString::encode($language));
        }
        if ($this->accessibility->pdfUa) {
            $catalog->set('ViewerPreferences', Dictionary::of(['DisplayDocTitle' => true]));
        }
    }
}
