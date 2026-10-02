<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

/**
 * One operation of a content stream: its operator, its parsed operands, and the bytes it spans,
 * from the end of the operation before it (so with the whitespace between) to the end of its
 * operator. An inline image (BI) spans its data to EI and carries its dictionary.
 *
 * @internal
 */
final readonly class ContentOperation
{
    /**
     * @param list<mixed>               $operands
     * @param array<string, mixed>|null $inlineImage the inline image's entries, for BI
     */
    public function __construct(
        public string $operator,
        public array $operands,
        public int $start,
        public int $end,
        public ?array $inlineImage = null,
    ) {}
}
