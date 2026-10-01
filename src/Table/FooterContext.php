<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

/**
 * The body rows a box holds, given to a table's footer callback so it can fill footer cells such
 * as a subtotal carried forward. Row numbers are 0-based indexes into Table::rows().
 *
 * @api
 */
final readonly class FooterContext
{
    public function __construct(
        public int $firstRow,
        public int $lastRow,
        public bool $isLastBox,
    ) {}
}
