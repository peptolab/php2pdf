<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Object;

/**
 * An indirect object reference (n g R).
 *
 * @api
 */
final readonly class Reference
{
    public function __construct(
        public int $id,
        public int $generation = 0,
    ) {}
}
