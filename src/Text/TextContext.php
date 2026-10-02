<?php

declare(strict_types=1);

namespace Php2Pdf\Text;

use Php2Pdf\Font\FontRegistry;
use Php2Pdf\Text\Style\StyleRegistry;

/**
 * What text is set with in a document: its fonts, named styles and inline objects, which styles
 * and markup refer to by name.
 *
 * @internal
 */
final readonly class TextContext
{
    public function __construct(
        public FontRegistry $fonts,
        public StyleRegistry $styles,
        public InlineRegistry $inlines,
    ) {}
}
