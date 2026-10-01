<?php

declare(strict_types=1);

namespace PhpPdf\Core;

use PhpPdf\Core\Filter\Flate;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\HexString;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Core\Output\Output;
use PhpPdf\Exception\PdfException;

/**
 * Streams indirect objects to an Output as they are written and emits the cross-reference table
 * and trailer at the end. Object numbers can be allocated ahead of time so objects may reference
 * each other before being written.
 */
final class Writer
{
    private int $nextId = 1;

    /** @var array<int, int> object number => byte offset */
    private array $offsets = [];

    private \HashContext $hash;

    private bool $finished = false;

    public function __construct(
        private readonly Output $output,
        private readonly bool $compress = true,
        private readonly int $compressionLevel = 6,
    ) {
        $this->hash = hash_init('md5');
    }

    public function writeHeader(string $version): void
    {
        // The binary comment line tells transfer tools this is not a text file.
        $this->emit("%PDF-{$version}\n%\xE2\xE3\xCF\xD3\n");
    }

    public function allocate(): Reference
    {
        return new Reference($this->nextId++);
    }

    public function isWritten(Reference $ref): bool
    {
        return isset($this->offsets[$ref->id]);
    }

    /**
     * Writes an object under a previously allocated number.
     */
    public function write(Reference $ref, mixed $object): void
    {
        if ($this->finished) {
            throw new PdfException('Cannot write objects after the document has been finished.');
        }
        if (isset($this->offsets[$ref->id])) {
            throw new PdfException(sprintf('Object %d has already been written.', $ref->id));
        }
        $this->offsets[$ref->id] = $this->output->position();

        $body = $object instanceof Stream ? $this->streamBody($object) : Serializer::serialize($object);
        $this->emit("{$ref->id} 0 obj\n{$body}\nendobj\n");
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

    public function finish(Dictionary $trailer): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        $size = $this->nextId;
        for ($id = 1; $id < $size; $id++) {
            if (!isset($this->offsets[$id])) {
                throw new PdfException(sprintf('Object %d was allocated but never written.', $id));
            }
        }

        $xrefOffset = $this->output->position();
        $xref = "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $xref .= sprintf("%010d 00000 n \n", $this->offsets[$id]);
        }
        $this->emit($xref);

        $id = hash_final($this->hash, true);
        $trailer->set('Size', $size);
        if (!$trailer->has('ID')) {
            $trailer->set('ID', [new HexString($id), new HexString($id)]);
        }

        $this->output->write("trailer\n" . Serializer::serialize($trailer) . "\nstartxref\n{$xrefOffset}\n%%EOF\n");
        $this->output->close();
    }

    public function position(): int
    {
        return $this->output->position();
    }

    private function streamBody(Stream $stream): string
    {
        $dict = $stream->dictionary;
        $data = $stream->data;
        if ($this->compress && $stream->compress && !$dict->has('Filter')) {
            $data = Flate::encode($data, $this->compressionLevel);
            $dict->set('Filter', new Name('FlateDecode'));
        }
        $dict->set('Length', strlen($data));

        return Serializer::serialize($dict) . "\nstream\n" . $data . "\nendstream";
    }

    private function emit(string $bytes): void
    {
        hash_update($this->hash, $bytes);
        $this->output->write($bytes);
    }
}
