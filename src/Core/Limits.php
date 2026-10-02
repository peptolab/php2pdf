<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use Php2Pdf\Exception\InvalidArgumentException;

/**
 * Resource limits for parsing files that might be hostile: PDFs to import, images and SVG. A
 * file over a limit fails with a PdfException instead of exhausting the worker's memory or time.
 * The defaults suit ordinary print work; lower them for untrusted uploads.
 *
 * @api
 */
final readonly class Limits
{
    /**
     * @param int $maxDecodedBytes  the largest a single decompressed stream may grow
     * @param int $maxImagePixels   the most pixels an image may have (width × height)
     * @param int $maxPages         the most pages an imported PDF may have
     * @param int $maxXrefEntries   the most cross-reference entries an imported PDF may declare
     * @param int $maxNesting       the deepest SVG elements may nest
     * @param int $maxSvgUses       the most <use> references one SVG may expand while drawing
     * @param int $maxObjectNesting the deepest an imported PDF's objects may nest: arrays and
     *                              dictionaries within each other, page tree levels, chains of
     *                              references, and resources within forms
     */
    public function __construct(
        public int $maxDecodedBytes = 256 * 1024 * 1024,
        public int $maxImagePixels = 100_000_000,
        public int $maxPages = 100_000,
        public int $maxXrefEntries = 10_000_000,
        public int $maxNesting = 256,
        public int $maxSvgUses = 10_000,
        public int $maxObjectNesting = 256,
    ) {
        foreach ([
            $maxDecodedBytes,
            $maxImagePixels,
            $maxPages,
            $maxXrefEntries,
            $maxNesting,
            $maxSvgUses,
            $maxObjectNesting,
        ] as $limit) {
            if ($limit < 1) {
                throw new InvalidArgumentException('Limits must be at least 1.');
            }
        }
    }

    public static function default(): self
    {
        static $default = null;

        return $default ??= new self();
    }
}
