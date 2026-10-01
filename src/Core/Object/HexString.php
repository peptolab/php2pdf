<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Object;

/**
 * A byte string serialised in hexadecimal form (<...>). Use for binary data such as /ID.
 *
 * @api
 */
final readonly class HexString
{
    public function __construct(
        public string $bytes,
    ) {}
}
