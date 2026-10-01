<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Core\Clock;
use PhpPdf\Core\SystemClock;

/**
 * Options fixed for the lifetime of a document.
 *
 * @api
 */
final readonly class DocumentOptions
{
    public Clock $clock;

    public function __construct(
        public string $version = '1.7',
        public bool $compress = true,
        public int $compressionLevel = 6,
        Clock|null $clock = null,
        public string $producer = 'PhpPdf',
        public bool $xmp = true,
        /** Use a top-left origin with y growing downwards on every page unless a page says otherwise. */
        public bool $topDown = false,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }
}
