<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType\Cff;

/**
 * One operator of a CFF DICT: its operands as written, so they can be copied unchanged, and
 * their values.
 *
 * @internal
 */
final readonly class DictEntry
{
    /** @param list<int|float> $values */
    public function __construct(
        public string $operands,
        public array $values,
    ) {}
}
