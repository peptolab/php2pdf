<?php

declare(strict_types=1);

namespace PhpPdf\Document;

/**
 * An object whose PDF representation is written when the document closes, such as a font that is
 * subset to the glyphs actually used.
 *
 * @internal
 */
interface DeferredInterface
{
    public function finalize(Document $document): void;
}
