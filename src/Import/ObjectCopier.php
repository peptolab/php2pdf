<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ParseException;

use function is_array;

/**
 * Deep-copies objects from a source PDF into a document being written. Every source object is
 * written once, so fonts and images shared by several imported pages appear once in the output.
 * When hidden layers are removed, the content of the forms copied is filtered on the way. A
 * reference the policy does not follow is copied as null, which drops a dictionary entry.
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
        private readonly CopyPolicy $policy = new CopyPolicy(),
    ) {}

    /** The target reference of a source object already copied, or null. */
    public function copied(Reference $ref): ?Reference
    {
        return $this->map[$ref->id] ?? null;
    }

    /** @throws ParseException */
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

    /** @throws ParseException */
    public function copyDictionary(Dictionary $dict): Dictionary
    {
        $out = new Dictionary();
        foreach ($dict->all() as $key => $value) {
            $out->set($key, $this->copy($value));
        }

        return $out;
    }

    /**
     * The target reference, allocated before the object is copied so that cycles resolve to it;
     * null for an object the policy does not follow.
     *
     * @throws ParseException
     */
    private function copyReference(Reference $ref): ?Reference
    {
        $mapped = $this->map[$ref->id] ?? null;
        if (null !== $mapped) {
            return $mapped;
        }
        $object = $this->source->object($ref->id);
        if (! $this->policy->follows($object)) {
            return null;
        }
        $target              = $this->target->allocate();
        $this->map[$ref->id] = $target;
        $this->target->write($target, $this->copy($object));

        return $target;
    }

    /**
     * Copies a stream as it is, except a form when hidden layers are removed: its content is
     * decoded, filtered and compressed again.
     *
     * @throws ParseException
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
