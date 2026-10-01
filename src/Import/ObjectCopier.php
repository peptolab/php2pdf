<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Core\Writer;

use function is_array;

/**
 * Deep-copies objects from a source PDF into a document being written. Every source object is
 * written once, so fonts and images shared by several imported pages appear once in the output.
 *
 * @internal
 */
final class ObjectCopier
{
    /** @var array<int, Reference> source object number => target reference */
    private array $map = [];

    public function __construct(
        private readonly PdfReader $source,
        private readonly Writer $target,
    ) {}

    public function copy(mixed $value): mixed
    {
        if ($value instanceof Reference) {
            return $this->copyReference($value);
        }
        if ($value instanceof Stream) {
            return new Stream($this->copyDictionary($value->dictionary), $value->data, compress: false);
        }
        if ($value instanceof Dictionary) {
            return $this->copyDictionary($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->copy($item);
            }

            return $out;
        }

        return $value;
    }

    public function copyDictionary(Dictionary $dict): Dictionary
    {
        $out = new Dictionary();
        foreach ($dict->all() as $key => $value) {
            $out->set($key, $this->copy($value));
        }

        return $out;
    }

    private function copyReference(Reference $ref): Reference
    {
        $mapped = $this->map[$ref->id] ?? null;
        if (null !== $mapped) {
            return $mapped;
        }
        // Allocate before descending so cycles (such as /Parent links) resolve to this object.
        $target              = $this->target->allocate();
        $this->map[$ref->id] = $target;
        $this->target->write($target, $this->copy($this->source->object($ref->id)));

        return $target;
    }
}
