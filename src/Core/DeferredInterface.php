<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

/**
 * An object written when the document closes, once everything it depends on is known: a font
 * subset once all its glyphs are used, a template once it is drawn.
 *
 * @internal
 */
interface DeferredInterface
{
    public function finalize(): void;
}
