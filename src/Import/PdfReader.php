<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use PhpPdf\Core\Filter\FilterChain;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Exception\IoException;
use PhpPdf\Exception\PdfException;

use function array_key_exists;
use function array_keys;
use function count;
use function file_get_contents;
use function is_array;
use function is_file;
use function preg_match;
use function sprintf;
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

    private function __construct(string $data)
    {
        $this->lexer      = new Lexer($data);
        $this->parser     = new ObjectParser($this->lexer, $this->resolve(...));
        $this->xrefParser = new XrefParser($this->lexer, $this->parser, $this->decode(...));
        $m                = [];
        $this->version    = preg_match('/%PDF-(\d\.\d)/', substr($data, offset: 0, length: 1024), $m) === 1
            ? $m[1] ?? '1.4'
            : '1.4';
        $this->xref = new XrefTable();

        try {
            $this->xrefParser->readChain($this->xrefParser->startXref(), $this->xref);
        } catch (PdfException) {
            $this->repair();
        }
        if (! $this->xref->trailer->has('Root')) {
            $this->repair();
        }
        if ($this->xref->trailer->has('Encrypt')) {
            throw new PdfException('Encrypted PDF files are not supported.');
        }
    }

    public static function fromData(string $data): self
    {
        if (! str_contains(substr($data, offset: 0, length: 1024), '%PDF-')) {
            throw new PdfException('Not a PDF file.');
        }

        return new self($data);
    }

    public static function fromFile(string $path): self
    {
        $data = is_file($path) ? file_get_contents($path) : false;
        if (false === $data) {
            throw new IoException(sprintf('Cannot read PDF file "%s".', $path));
        }

        return self::fromData($data);
    }

    public function catalog(): Dictionary
    {
        $root = $this->resolve($this->xref->trailer->get('Root'));
        if (! $root instanceof Dictionary) {
            throw new PdfException('PDF has no catalog.');
        }

        return $root;
    }

    /** The decoded data of a stream, or the raw data when it ends in an image codec. */
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

        return FilterChain::decode($stream->data, $filters, $parms);
    }

    /** A dictionary entry that must be a dictionary (streams count), or null. */
    public function dict(Dictionary $dict, string $key): ?Dictionary
    {
        $value = $this->get($dict, $key);

        return match (true) {
            $value instanceof Dictionary => $value,
            $value instanceof Stream => $value->dictionary,
            default => null,
        };
    }

    /** A dictionary entry with references followed. */
    public function get(Dictionary $dict, string $key): mixed
    {
        return $this->resolve($dict->get($key));
    }

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
     */
    public function pages(): array
    {
        $root = $this->dict($this->catalog(), 'Pages');
        if (null === $root) {
            throw new PdfException('PDF has no page tree.');
        }
        $pages = [];
        $this->walkPages($root, new Dictionary(), $pages, [], 0);

        return $pages;
    }

    /** Follows a reference to its object; other values pass through. */
    public function resolve(mixed $value): mixed
    {
        $depth = 0;
        while ($value instanceof Reference && $depth++ < 32) {
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

    /** Registers objects living in object streams and finds the catalog when no trailer names it. */
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
        $body = new ObjectParser(new Lexer($data));
        foreach ($this->objectStreamOffsets($stream, $data) as $num => $offset) {
            // An object redefined in a later update, or living in another stream, keeps precedence.
            $entry = $this->xref->entry($num);
            if (null !== $entry && (XrefTable::IN_STREAM !== $entry[0] || $entry[1] !== $streamNumber)) {
                continue;
            }
            $body->lexer()->seek($offset);
            $this->cache[$num] = $body->parse();
        }
    }

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
        } catch (PdfException) {
            return null;
        }

        return $number === $expected ? $object : null;
    }

    private function registerObjectStream(int $number, Stream $stream): void
    {
        try {
            $offsets = $this->objectStreamOffsets($stream, $this->decode($stream));
        } catch (PdfException) {
            return;
        }
        $index = 0;
        foreach (array_keys($offsets) as $num) {
            $this->xref->addInStream($num, $number, $index++);
        }
    }

    /**
     * Rebuilds the cross-reference by scanning the file, keeping whatever the trailer already said.
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
            throw new PdfException('PDF is damaged beyond repair: no catalog found.');
        }
    }

    private function resolveDictionary(Dictionary $dict): Dictionary
    {
        $out = new Dictionary();
        foreach ($dict->all() as $key => $value) {
            $out->set($key, $this->resolve($value));
        }

        return $out;
    }

    /**
     * @param list<Dictionary> $pages
     * @param array<int, true> $visited
     */
    private function walkPages(Dictionary $node, Dictionary $inherited, array &$pages, array $visited, int $depth): void
    {
        if ($depth > 64) {
            return;
        }
        $merged = new Dictionary($inherited->all());
        foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $key) {
            if (! $node->has($key)) {
                continue;
            }
            $merged->set($key, $node->get($key));
        }
        $kids   = $this->get($node, 'Kids');
        $type   = $node->get('Type');
        $isPage = ($type instanceof Name && 'Page' === $type->value) || (! is_array($kids) && $node->has('Contents'));
        if ($isPage) {
            $page = new Dictionary($node->all());
            foreach ($merged->all() as $key => $value) {
                if ($page->has($key)) {
                    continue;
                }
                $page->set($key, $value);
            }
            $pages[] = $page;

            return;
        }
        if (! is_array($kids)) {
            return;
        }
        foreach ($kids as $kid) {
            $id = $kid instanceof Reference ? $kid->id : null;
            if (null !== $id && array_key_exists($id, $visited)) {
                continue;
            }
            $child = $this->resolve($kid);
            if (! $child instanceof Dictionary) {
                continue;
            }
            $childVisited = $visited;
            if (null !== $id) {
                $childVisited[$id] = true;
            }
            $this->walkPages($child, $merged, $pages, $childVisited, $depth + 1);
        }
    }
}
