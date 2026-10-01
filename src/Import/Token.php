<?php

declare(strict_types=1);

namespace PhpPdf\Import;

/**
 * A lexical token of PDF syntax.
 *
 * @internal
 */
final readonly class Token
{
    public const int NUMBER      = 1;
    public const int STRING      = 2;
    public const int NAME        = 3;
    public const int ARRAY_OPEN  = 4;
    public const int ARRAY_CLOSE = 5;
    public const int DICT_OPEN   = 6;
    public const int DICT_CLOSE  = 7;
    public const int KEYWORD     = 8;
    public const int BRACE_OPEN  = 9;
    public const int BRACE_CLOSE = 10;
    public const int END         = 11;

    public function __construct(
        public int $type,
        public string|int|float $value,
        /** Offset just past the token, so streams can be located after "stream". */
        public int $end,
    ) {}

    public function is(int $type, string|int|float|null $value = null): bool
    {
        return $this->type === $type && (null === $value || $this->value === $value);
    }
}
