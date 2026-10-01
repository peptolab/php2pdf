<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Closure;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Writer;

/**
 * The document's PDF objects, for artwork of your own that writes its own resources: add an
 * object (a Dictionary, a Stream, an array or a value) and get its reference, or share one by key
 * so it is written once however often it is asked for. Draw a form or image written here with
 * Canvas::drawXObject().
 *
 * @api
 */
final class Objects
{
    /** @var array<string, Reference> */
    private array $shared = [];

    /** @internal */
    public function __construct(
        private readonly Writer $writer,
    ) {}

    /** Writes an object and returns its reference. */
    public function add(mixed $object): Reference
    {
        return $this->writer->add($object);
    }

    /**
     * Writes the object the factory makes the first time a key is asked for, and returns the same
     * reference after that.
     *
     * @param Closure(): mixed $factory
     */
    public function shared(string $key, Closure $factory): Reference
    {
        return $this->shared[$key] ??= $this->writer->add($factory());
    }
}
