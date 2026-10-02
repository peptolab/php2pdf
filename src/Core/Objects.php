<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use Closure;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Exception\InvalidArgumentException;

use function sprintf;

/**
 * The document's PDF objects, for artwork of your own that writes its own resources: add an
 * object (a Dictionary, a Stream, an array or a value) and get its reference, or share one by key
 * so it is written once however often it is asked for. Draw a form or image written here with
 * Canvas::drawXObject().
 *
 * Inside the library this is how everything writes its objects: fonts, images, templates and
 * imported pages hold the Objects of the document that made them, and refuse to be drawn into
 * another.
 *
 * @api
 */
final class Objects
{
    /** @var array<string, Reference> */
    private array $shared = [];

    /** @var list<DeferredInterface> */
    private array $deferred = [];

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
     * Reserves a reference for an object written later with write().
     *
     * @internal
     */
    public function allocate(): Reference
    {
        return $this->writer->allocate();
    }

    /**
     * Refuses a resource made for another document, which would refer to objects this one never
     * writes.
     *
     * @internal
     */
    public function assertOwns(self $owner, string $what): void
    {
        if ($owner !== $this) {
            throw new InvalidArgumentException(sprintf(
                '%s belongs to the document that created it and cannot be drawn into another.',
                $what,
            ));
        }
    }

    /**
     * Registers an object to be written when the document closes.
     *
     * @internal
     */
    public function defer(DeferredInterface $deferred): void
    {
        $this->deferred[] = $deferred;
    }

    /**
     * Writes the deferred objects, in the order they were registered.
     *
     * @internal
     */
    public function finalize(): void
    {
        foreach ($this->deferred as $deferred) {
            $deferred->finalize();
        }
        $this->deferred = [];
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

    /**
     * Writes an object at a reference allocated earlier.
     *
     * @internal
     */
    public function write(Reference $reference, mixed $object): void
    {
        $this->writer->write($reference, $object);
    }
}
