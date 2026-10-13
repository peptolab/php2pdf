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
use function count;
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
 * With object streams (PDF 1.5), objects other than streams are held until a hundred of them are
 * written together in one compressed object stream, and the cross-reference is a stream too,
 * which carries the trailer's entries.
 *
 * @internal
 */
final class Writer
{
    /** Streams of this many bytes or more are written in pieces rather than copied into one. */
    private const int LARGE_STREAM = 65_536;

    /** How many objects an object stream holds, as qpdf writes them. */
    private const int PER_OBJECT_STREAM = 100;

    private int $nextId = 1;

    /** @var array<int, int> object number => byte offset */
    private array $offsets = [];

    /** @var array<int, array{0: int, 1: int}> object number => the object stream holding it, and its index there */
    private array $packed = [];

    /** @var list<array{0: int, 1: string}> the objects held for the next object stream: number and bytes */
    private array $held = [];

    private HashContext $hash;

    private bool $finished = false;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly bool $compress = true,
        private readonly int $compressionLevel = 6,
        private readonly bool $objectStreams = false,
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
        if ($this->objectStreams) {
            $this->finishWithStream($trailer);

            return;
        }

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
        return array_key_exists($ref->id, $this->offsets) || array_key_exists($ref->id, $this->packed);
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
        if ($this->isWritten($ref)) {
            throw new StateException(sprintf('Object %d has already been written.', $ref->id));
        }
        if ($this->objectStreams && ! $object instanceof Stream) {
            $this->packed[$ref->id] = [0, count($this->held)];
            $this->held[]           = [$ref->id, Serializer::serialize($object)];
            if (count($this->held) >= self::PER_OBJECT_STREAM) {
                $this->pack();
            }

            return;
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
     * Writes the cross-reference as a stream (PDF 1.5) with the trailer's entries, after the
     * objects still held for an object stream.
     */
    private function finishWithStream(Dictionary $trailer): void
    {
        if ([] !== $this->held) {
            $this->pack();
        }
        $xref                 = $this->nextId++;
        $size                 = $this->nextId;
        $xrefOffset           = $this->output->position();
        $this->offsets[$xref] = $xrefOffset;
        $entries              = [[0, 0, 65_535]];
        for ($id = 1; $id < $size; ++$id) {
            $packed    = $this->packed[$id] ?? [0, 0];
            $entries[] = match (true) {
                array_key_exists($id, $this->offsets) => [1, $this->offsets[$id], 0],
                0 !== $packed[0] => [2, $packed[0], $packed[1]],
                default => throw new StateException(sprintf(
                    'Object %d was allocated but never written.',
                    $id,
                )),
            };
        }
        [$widths, $rows] = XrefStream::encode($entries);
        $id   = hash_final($this->hash, binary: true);
        $dict = new Dictionary($trailer->all());
        $dict->set('Type', new Name('XRef'));
        $dict->set('Size', $size);
        $dict->set('W', $widths);
        if (! $dict->has('ID')) {
            $dict->set('ID', [new HexString($id), new HexString($id)]);
        }
        $data = Flate::encode($rows, $this->compressionLevel);
        $dict->set('Filter', new Name('FlateDecode'));
        $dict->set('DecodeParms', Dictionary::of([
            'Predictor' => 12,
            'Columns'   => $widths[0] + $widths[1] + $widths[2],
        ]));
        $dict->set('Length', strlen($data));
        $this->output->write(
            "{$xref} 0 obj\n"
                . Serializer::serialize($dict)
                . "\nstream\n{$data}\nendstream\nendobj\n"
                . "startxref\n{$xrefOffset}\n%%EOF\n",
        );
        $this->output->close();
    }

    /** Writes the objects held as one object stream, and records where each went. */
    private function pack(): void
    {
        $stream = $this->nextId++;
        $count  = count($this->held);
        $index  = '';
        $body   = '';
        foreach ($this->held as $i => [$number, $bytes]) {
            $index                 .= "{$number} " . strlen($body) . ' ';
            $body                  .= "{$bytes}\n";
            $this->packed[$number] = [$stream, $i];
        }
        $this->held             = [];
        $this->offsets[$stream] = $this->output->position();
        $this->writeStream(
            new Reference($stream),
            new Stream(Dictionary::of([
                'Type'  => new Name('ObjStm'),
                'N'     => $count,
                'First' => strlen($index),
            ]), $index . $body),
        );
    }

    /**
     * Writes a stream object, its /Filter and /Length worked out on a copy of the dictionary, so
     * the caller's Stream is left as it was and can be written again. Large data goes to the output
     * as it is, not joined to the rest, so it is not copied on the way; small data is written with
     * the rest in one piece.
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
        $head = "{$ref->id} 0 obj\n" . Serializer::serialize($dict) . "\nstream\n";
        if (strlen($data) < self::LARGE_STREAM) {
            $this->emit("{$head}{$data}\nendstream\nendobj\n");

            return;
        }
        $this->emit($head);
        $this->emit($data);
        $this->emit("\nendstream\nendobj\n");
    }
}
