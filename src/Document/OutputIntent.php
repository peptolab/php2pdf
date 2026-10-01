<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Core\Object\Dictionary;

/**
 * The output intent a document declares: its dictionary and the colour components (1, 3 or 4)
 * of its profile, which decide the device colours PDF/X allows.
 *
 * @internal
 */
final readonly class OutputIntent
{
    public function __construct(
        public Dictionary $dictionary,
        public int $components,
    ) {}
}
