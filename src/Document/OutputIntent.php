<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Php2Pdf\Core\Object\Reference;

/**
 * The output intent a document declares: its printing condition, its profile's stream, and the
 * colour components (1, 3 or 4) of that profile, which decide the device colours PDF/X and PDF/A
 * allow.
 *
 * @internal
 */
final readonly class OutputIntent
{
    public function __construct(
        public string $identifier,
        public string $info,
        public Reference $profile,
        public int $components,
    ) {}
}
