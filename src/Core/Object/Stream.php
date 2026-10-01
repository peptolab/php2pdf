<?php

declare(strict_types=1);

namespace PhpPdf\Core\Object;

/**
 * A PDF stream: a dictionary plus binary data.
 *
 * When $compress is true and the dictionary carries no /Filter, the writer applies FlateDecode
 * if the document is configured to compress. Data that is already encoded (JPEG, pre-deflated PNG
 * rows) sets its own /Filter and is passed through untouched.
 */
final class Stream
{
    public function __construct(
        public readonly Dictionary $dictionary,
        public string $data,
        public bool $compress = true,
    ) {}

    public static function of(string $data, ?Dictionary $dictionary = null, bool $compress = true): self
    {
        return new self($dictionary ?? new Dictionary(), $data, $compress);
    }
}
