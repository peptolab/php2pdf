<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;

use function is_array;

/**
 * Deep-copies objects from a source PDF into a document being written. Every source object is
 * written once, so fonts and images shared by several imported pages appear once in the output.
 * When hidden layers are removed, the content of the forms copied is filtered on the way.
 *
 * @internal
 */
final class ObjectCopier
{
    /** @var array<int, Reference> source object number => target reference */
    private array $map = [];

    public function __construct(
        private readonly PdfReader $source,
        private readonly Objects $target,
        private readonly ?HiddenContent $hidden = null,
    ) {}

    /** The target reference of a source object already copied, or null. */
    public function copied(Reference $ref): ?Reference
    {
        return $this->map[$ref->id] ?? null;
    }

    public function copy(mixed $value): mixed
    {
        if ($value instanceof Reference) {
            return $this->copyReference($value);
        }
        if ($value instanceof Stream) {
            return $this->copyStream($value);
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

    /**
     * Copies a stream as it is, except a form when hidden layers are removed: its content is
     * decoded, filtered and compressed again.
     */
    private function copyStream(Stream $stream): Stream
    {
        $dict    = $this->copyDictionary($stream->dictionary);
        $subtype = $this->source->get($stream->dictionary, 'Subtype');
        if (null === $this->hidden || ! $subtype instanceof Name || 'Form' !== $subtype->value) {
            return new Stream($dict, $stream->data, compress: false);
        }
        $content = $this->hidden->filter(
            $this->source->decode($stream),
            $this->source->dict($stream->dictionary, 'Resources'),
        );
        $dict->remove('Filter');
        $dict->remove('DecodeParms');

        return new Stream($dict, $content);
    }
}
