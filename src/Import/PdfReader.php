<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\FileReader;
use Php2Pdf\Core\Filter\FilterChain;
use Php2Pdf\Core\Limits;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ExceptionInterface;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function array_keys;
use function count;
use function preg_match;
use function str_contains;
use function substr;

/**
 * Reads an existing PDF: objects are parsed on demand from the cross-reference (tables, streams,
 * object streams, chains of incremental updates) and cached. When offsets are wrong the
 * cross-reference is rebuilt by scanning the file.
 *
 * @internal
 */
final class PdfReader
{
    private readonly Lexer $lexer;
    private readonly ObjectParser $parser;
    private readonly XrefParser $xrefParser;
    private readonly string $version;
    private XrefTable $xref;

    /** @var array<int, mixed> */
    private array $cache = [];

    /** @var array<int, true> object streams already expanded */
    private array $expandedStreams = [];

    /** @var array<int, true> objects currently being loaded, to break reference cycles */
    private array $loading = [];

    private bool $repaired = false;

    /** @throws ParseException */
    private function __construct(
        string $data,
        private readonly Limits $limits,
    ) {
        $this->lexer      = new Lexer($data);
        $this->parser     = new ObjectParser($this->lexer, $this->resolve(...), $limits->maxObjectNesting);
        $this->xrefParser = new XrefParser($this->lexer, $this->parser, $this->decode(...), $limits->maxXrefEntries);
        $m                = [];
        $this->version    = preg_match('/%PDF-(\d\.\d)/', substr($data, offset: 0, length: 1024), $m) === 1
            ? $m[1] ?? '1.4'
            : '1.4';
        $this->xref = new XrefTable();

        try {
            $this->xrefParser->readChain($this->xrefParser->startXref(), $this->xref);
        } catch (ExceptionInterface) {
            $this->repair();
        }
        if (! $this->xref->trailer->has('Root')) {
            $this->repair();
        }
        if ($this->xref->trailer->has('Encrypt')) {
            throw new ParseException('Encrypted PDF files are not supported.');
        }
    }

    /** @throws ParseException */
    public static function fromData(string $data, ?Limits $limits = null): self
    {
        if (! str_contains(substr($data, offset: 0, length: 1024), '%PDF-')) {
            throw new ParseException('Not a PDF file.');
        }

        return new self($data, $limits ?? Limits::default());
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public static function fromFile(string $path, ?Limits $limits = null): self
    {
        return self::fromData(FileReader::read($path, 'PDF file'), $limits);
    }

    /** @throws ParseException */
    public function catalog(): Dictionary
    {
        $root = $this->resolve($this->xref->trailer->get('Root'));
        if (! $root instanceof Dictionary) {
            throw new ParseException('PDF has no catalog.');
        }

        return $root;
    }

    /**
     * The decoded data of a stream, or the raw data when it ends in an image codec.
     *
     * @throws ParseException
     */
    public function decode(Stream $stream): string
    {
        $filters = FilterChain::filters($this->get($stream->dictionary, 'Filter'));
        if (FilterChain::endsInImageCodec($filters)) {
            return $stream->data;
        }
        $parms = [];
        foreach (FilterChain::parms($this->get($stream->dictionary, 'DecodeParms'), count($filters)) as $p) {
            $parms[] = null === $p ? null : $this->resolveDictionary($p);
        }

        return FilterChain::decode($stream->data, $filters, $parms, $this->limits->maxDecodedBytes);
    }

    /**
     * A dictionary entry that must be a dictionary (streams count), or null.
     *
     * @throws ParseException
     */
    public function dict(Dictionary $dict, string $key): ?Dictionary
    {
        $value = $this->get($dict, $key);

        return match (true) {
            $value instanceof Dictionary => $value,
            $value instanceof Stream => $value->dictionary,
            default => null,
        };
    }

    /**
     * A dictionary entry with references followed.
     *
     * @throws ParseException
     */
    public function get(Dictionary $dict, string $key): mixed
    {
        return $this->resolve($dict->get($key));
    }

    /** The limits this file is read under. */
    public function limits(): Limits
    {
        return $this->limits;
    }

    /** @throws ParseException */
    public function object(int $number): mixed
    {
        if (array_key_exists($number, $this->cache)) {
            return $this->cache[$number];
        }
        if (array_key_exists($number, $this->loading)) {
            return null;
        }
        $this->loading[$number] = true;
        try {
            $object = $this->load($number);
            if (null === $object && ! $this->repaired) {
                $this->repair();
                $object = $this->load($number);
            }
        } finally {
            unset($this->loading[$number]);
        }

        return $this->cache[$number] = $object;
    }

    /**
     * The page dictionaries in document order, with inheritable attributes filled in.
     *
     * @return list<Dictionary>
     *
     * @throws ParseException
     */
    public function pages(): array
    {
        $root = $this->dict($this->catalog(), 'Pages');
        if (null === $root) {
            throw new ParseException('PDF has no page tree.');
        }
        return (new PageTree($this))->pages($root);
    }

    /**
     * Follows a reference to its object; other values pass through.
     *
     * @throws ParseException
     */
    public function resolve(mixed $value): mixed
    {
        $depth = 0;
        while ($value instanceof Reference && $depth++ < $this->limits->maxObjectNesting) {
            $value = $this->object($value->id);
        }

        return $value;
    }

    public function trailer(): Dictionary
    {
        return $this->xref->trailer;
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * Registers objects living in object streams and finds the catalog when no trailer names it.
     *
     * @throws ParseException
     */
    private function collectFromObjects(): void
    {
        $found = new Dictionary();
        foreach ($this->xref->numbers() as $number) {
            $object = $this->object($number);
            $dict   = $object instanceof Stream ? $object->dictionary : $object;
            if (! $dict instanceof Dictionary) {
                continue;
            }
            $type = $dict->get('Type');
            $type = $type instanceof Name ? $type->value : '';
            if ('XRef' === $type) {
                foreach (['Root', 'Info', 'ID', 'Encrypt'] as $key) {
                    if (! $dict->has($key)) {
                        continue;
                    }
                    $found->set($key, $dict->get($key));
                }
                continue;
            }
            if ('ObjStm' === $type && $object instanceof Stream) {
                $this->registerObjectStream($number, $object);
                continue;
            }
            if ('Catalog' === $type && ! $found->has('Root')) {
                $found->set('Root', new Reference($number));
            }
        }
        $this->xref->mergeTrailer($found);
    }

    /** @throws ParseException */
    private function expandObjectStream(int $streamNumber): void
    {
        if (array_key_exists($streamNumber, $this->expandedStreams)) {
            return;
        }
        $this->expandedStreams[$streamNumber] = true;
        $stream                               = $this->object($streamNumber);
        if (! $stream instanceof Stream) {
            return;
        }
        $data = $this->decode($stream);
        $body = new ObjectParser(new Lexer($data), maxNesting: $this->limits->maxObjectNesting);
        foreach ($this->objectStreamOffsets($stream, $data) as $num => $offset) {
            // An object redefined in a later update, or living in another stream, keeps precedence.
            $entry = $this->xref->entry($num);
            if (null !== $entry && (XrefTable::IN_STREAM !== $entry[0] || $entry[1] !== $streamNumber)) {
                continue;
            }
            $body->lexer()->seek($offset);
            $this->cache[$num] = $this->parseInStream($body);
        }
    }

    /** @throws ParseException */
    private function load(int $number): mixed
    {
        $entry = $this->xref->entry($number);
        if (null === $entry) {
            return null;
        }
        if (XrefTable::IN_STREAM === $entry[0]) {
            $this->expandObjectStream($entry[1]);

            return $this->cache[$number] ?? null;
        }

        return $this->parseAt($entry[1], $number);
    }

    /**
     * The object numbers and absolute data offsets listed in an object stream's header.
     *
     * @return array<int, int>
     *
     * @throws ParseException
     */
    private function objectStreamOffsets(Stream $stream, string $data): array
    {
        $count   = (int) $this->get($stream->dictionary, 'N');
        $first   = (int) $this->get($stream->dictionary, 'First');
        $head    = new Lexer(substr($data, offset: 0, length: $first));
        $offsets = [];
        for ($i = 0; $i < $count; ++$i) {
            $number = $head->next();
            $offset = $head->next();
            if (! $number->is(Token::NUMBER) || ! $offset->is(Token::NUMBER)) {
                break;
            }
            $offsets[(int) $number->value] = $first + (int) $offset->value;
        }

        return $offsets;
    }

    /** Parses the indirect object at $offset, returning null when a different object sits there. */
    private function parseAt(int $offset, int $expected): mixed
    {
        if ($offset < 0 || $offset >= $this->lexer->length()) {
            return null;
        }
        try {
            $this->lexer->seek($offset);
            [$number, , $object] = $this->parser->parseIndirect();
        } catch (ExceptionInterface) {
            return null;
        }

        return $number === $expected ? $object : null;
    }

    /** Parses one object of an object stream, or null when it is broken, as a broken object in the file is. */
    private function parseInStream(ObjectParser $body): mixed
    {
        try {
            return $body->parse();
        } catch (ExceptionInterface) {
            return null;
        }
    }

    private function registerObjectStream(int $number, Stream $stream): void
    {
        try {
            $offsets = $this->objectStreamOffsets($stream, $this->decode($stream));
        } catch (ExceptionInterface) {
            return;
        }
        $index = 0;
        foreach (array_keys($offsets) as $num) {
            $this->xref->addInStream($num, $number, $index++);
        }
    }

    /**
     * Rebuilds the cross-reference by scanning the file, keeping whatever the trailer already said.
     *
     * @throws ParseException
     */
    private function repair(): void
    {
        $this->repaired        = true;
        $this->cache           = [];
        $this->expandedStreams = [];
        $previous              = $this->xref->trailer;
        $this->xref            = new XrefTable();
        $this->xref->mergeTrailer($previous);
        $this->xrefParser->scan($this->xref);
        $this->collectFromObjects();
        if (! $this->xref->trailer->has('Root')) {
            throw new ParseException('PDF is damaged beyond repair: no catalog found.');
        }
    }

    /** @throws ParseException */
    private function resolveDictionary(Dictionary $dict): Dictionary
    {
        $out = new Dictionary();
        foreach ($dict->all() as $key => $value) {
            $out->set($key, $this->resolve($value));
        }

        return $out;
    }
}
