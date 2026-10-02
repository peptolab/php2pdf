<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use HashContext;
use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\HexString;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Output\OutputInterface;
use Php2Pdf\Exception\StateException;

use function array_key_exists;
use function hash_final;
use function hash_init;
use function hash_update;
use function sprintf;
use function strlen;

/**
 * Streams indirect objects to an Output as they are written and emits the cross-reference table
 * and trailer at the end. Object numbers can be allocated ahead of time so objects may reference
 * each other before being written.
 *
 * @internal
 */
final class Writer
{
    private int $nextId = 1;

    /** @var array<int, int> object number => byte offset */
    private array $offsets = [];

    private HashContext $hash;

    private bool $finished = false;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $compress = true,
        private readonly int $compressionLevel = 6,
    ) {
        $this->hash = hash_init('md5');
    }

    /**
     * Allocates a number and writes the object in one step.
     */
    public function add(mixed $object): Reference
    {
        $ref = $this->allocate();
        $this->write($ref, $object);

        return $ref;
    }

    public function allocate(): Reference
    {
        return new Reference($this->nextId++);
    }

    public function finish(Dictionary $trailer): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        $size       = $this->nextId;
        $xrefOffset = $this->output->position();
        $xref       = "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; ++$id) {
            $offset = $this->offsets[$id] ?? throw new StateException(sprintf(
                'Object %d was allocated but never written.',
                $id,
            ));
            $xref .= sprintf("%010d 00000 n \n", $offset);
        }
        $this->emit($xref);

        $id = hash_final($this->hash, binary: true);
        $trailer->set('Size', $size);
        if (! $trailer->has('ID')) {
            $trailer->set('ID', [new HexString($id), new HexString($id)]);
        }

        $this->output->write("trailer\n" . Serializer::serialize($trailer) . "\nstartxref\n{$xrefOffset}\n%%EOF\n");
        $this->output->close();
    }

    public function isWritten(Reference $ref): bool
    {
        return array_key_exists($ref->id, $this->offsets);
    }

    public function position(): int
    {
        return $this->output->position();
    }

    /**
     * Writes an object under a previously allocated number.
     */
    public function write(Reference $ref, mixed $object): void
    {
        if ($this->finished) {
            throw new StateException('Cannot write objects after the document has been finished.');
        }
        if (array_key_exists($ref->id, $this->offsets)) {
            throw new StateException(sprintf('Object %d has already been written.', $ref->id));
        }
        $this->offsets[$ref->id] = $this->output->position();
        if ($object instanceof Stream) {
            $this->writeStream($ref, $object);

            return;
        }
        $this->emit("{$ref->id} 0 obj\n" . Serializer::serialize($object) . "\nendobj\n");
    }

    public function writeHeader(string $version): void
    {
        // The binary comment line tells transfer tools this is not a text file.
        $this->emit("%PDF-{$version}\n%\xE2\xE3\xCF\xD3\n");
    }

    private function emit(string $bytes): void
    {
        hash_update($this->hash, $bytes);
        $this->output->write($bytes);
    }

    /**
     * Writes a stream object, its /Filter and /Length worked out on a copy of the dictionary, so
     * the caller's Stream is left as it was and can be written again. The data goes to the output
     * as it is, not joined to the rest, so a large stream is not copied on the way.
     */
    private function writeStream(Reference $ref, Stream $stream): void
    {
        $dict = new Dictionary($stream->dictionary->all());
        $data = $stream->data;
        if ($this->compress && $stream->compress && ! $dict->has('Filter')) {
            $data = Flate::encode($data, $this->compressionLevel);
            $dict->set('Filter', new Name('FlateDecode'));
        }
        $dict->set('Length', strlen($data));
        $this->emit("{$ref->id} 0 obj\n" . Serializer::serialize($dict) . "\nstream\n");
        $this->emit($data);
        $this->emit("\nendstream\nendobj\n");
    }
}
