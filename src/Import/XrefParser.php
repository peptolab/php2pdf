<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use Closure;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Exception\PdfException;

use function array_key_exists;
use function array_sum;
use function array_values;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function ord;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function strlen;
use function strrpos;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Reads cross-reference tables and streams, following /Prev and hybrid /XRefStm links, and
 * rebuilds a table by scanning the file when the real one cannot be trusted.
 *
 * @internal
 */
final readonly class XrefParser
{
    /** @param Closure(Stream): string $decode decodes a cross-reference stream */
    public function __construct(
        private Lexer $lexer,
        private ObjectParser $parser,
        private Closure $decode,
    ) {}

    /** @param list<int> $fields */
    private static function addStreamEntry(XrefTable $table, int $number, array $fields, int $typeWidth): void
    {
        $type = 0 === $typeWidth ? 1 : $fields[0] ?? 0;
        if (1 === $type) {
            $table->addInFile($number, $fields[1] ?? 0, $fields[2] ?? 0);
        }
        if (2 === $type) {
            $table->addInStream($number, $fields[1] ?? 0, $fields[2] ?? 0);
        }
    }

    /**
     * @param list<int> $widths
     *
     * @return list<int>
     */
    private static function fields(string $data, int $pos, array $widths): array
    {
        $fields = [];
        foreach ($widths as $width) {
            $value = 0;
            for ($b = 0; $b < $width; ++$b) {
                $value = ($value << 8) | ord($data[$pos++]);
            }
            $fields[] = $value;
        }

        return $fields;
    }

    /**
     * The /W field widths, validated.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function widths(mixed $w): array
    {
        if (! is_array($w) || count($w) < 3) {
            throw new PdfException('Cross-reference stream has no /W array.');
        }
        $values = array_values($w);

        return [
            is_int($values[0] ?? null) ? $values[0] : 0,
            is_int($values[1] ?? null) ? $values[1] : 0,
            is_int($values[2] ?? null) ? $values[2] : 0,
        ];
    }

    /** Reads the chain of sections starting at $offset into $table. */
    public function readChain(int $offset, XrefTable $table): void
    {
        $seen = [];
        while ($offset > 0 && $offset < $this->lexer->length() && ! array_key_exists($offset, $seen)) {
            $seen[$offset] = true;
            $section       = $this->readSection($offset, $table);
            $table->mergeTrailer($section);
            $hybrid = $section->get('XRefStm');
            if (is_int($hybrid) && ! array_key_exists($hybrid, $seen)) {
                $seen[$hybrid] = true;
                $this->readSection($hybrid, $table);
            }
            $prev   = $section->get('Prev');
            $offset = is_int($prev) || is_float($prev) ? (int) $prev : 0;
        }
    }

    /**
     * Rebuilds a table from every "n g obj" and "trailer" in the file. Objects inside object
     * streams are registered by the reader afterwards, once it can load the streams.
     */
    public function scan(XrefTable $table): void
    {
        $data    = $this->lexer->data();
        $matches = [];
        preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $data, $matches, flags: PREG_OFFSET_CAPTURE);
        foreach ($matches[1] ?? [] as $i => $match) {
            $generation = $matches[2][$i][0] ?? '0';
            $table->setInFile((int) ($match[0] ?? 0), (int) ($match[1] ?? 0), (int) $generation);
        }

        $found    = new Dictionary();
        $trailers = [];
        preg_match_all('/trailer/', $data, $trailers, flags: PREG_OFFSET_CAPTURE);
        foreach ($trailers[0] ?? [] as $match) {
            $this->lexer->seek((int) ($match[1] ?? 0) + 7);
            $dict = $this->parser->parse();
            if (! $dict instanceof Dictionary) {
                continue;
            }
            // Later trailers describe later updates and take precedence.
            foreach ($dict->all() as $key => $value) {
                $found->set($key, $value);
            }
        }
        $table->mergeTrailer($found);
    }

    /** The byte offset named by the last startxref in the file. */
    public function startXref(): int
    {
        $tail = substr($this->lexer->data(), offset: -2048);
        $pos  = strrpos($tail, needle: 'startxref');
        $m    = [];
        if (false === $pos || preg_match('/startxref\s+(\d+)/', $tail, $m, flags: 0, offset: $pos) !== 1) {
            throw new PdfException('No startxref found.');
        }

        return (int) ($m[1] ?? 0);
    }

    /** Reads one section (table or stream) and returns its trailer dictionary. */
    private function readSection(int $offset, XrefTable $table): Dictionary
    {
        $this->lexer->seek($offset);
        $token = $this->lexer->next();
        if ($token->is(Token::KEYWORD, 'xref')) {
            return $this->readTable($table);
        }
        if ($token->is(Token::NUMBER)) {
            $this->lexer->seek($offset);
            [, , $object] = $this->parser->parseIndirect();
            if ($object instanceof Stream) {
                return $this->readStream($object, $table);
            }
        }
        throw new PdfException(sprintf('No cross-reference section at offset %d.', $offset));
    }

    private function readStream(Stream $stream, XrefTable $table): Dictionary
    {
        $dict   = $stream->dictionary;
        $widths = self::widths($dict->get('W'));
        $size   = $dict->get('Size');
        $index  = $dict->get('Index');
        $ranges = is_array($index) ? array_values($index) : [0, is_int($size) ? $size : 0];
        $data   = ($this->decode)($stream);
        $row    = (int) array_sum($widths);
        $pos    = 0;
        for ($r = 0; ($r + 1) < count($ranges); $r += 2) {
            $start = (int) ($ranges[$r] ?? 0);
            $count = (int) ($ranges[$r + 1] ?? 0);
            for ($i = 0; $i < $count && ($pos + $row) <= strlen($data); ++$i, $pos += $row) {
                self::addStreamEntry($table, $start + $i, self::fields($data, $pos, $widths), $widths[0]);
            }
        }

        return $dict;
    }

    private function readTable(XrefTable $table): Dictionary
    {
        $data = $this->lexer->data();
        while (true) {
            $token = $this->lexer->next();
            if ($token->is(Token::KEYWORD, 'trailer')) {
                $trailer = $this->parser->parse();

                return $trailer instanceof Dictionary ? $trailer : new Dictionary();
            }
            $count = $this->lexer->next();
            if (! $token->is(Token::NUMBER) || ! $count->is(Token::NUMBER)) {
                throw new PdfException('Malformed cross-reference table.');
            }
            $start = (int) $token->value;
            $n     = (int) $count->value;
            $pos   = $this->lexer->position();
            for ($i = 0; $i < $n; ++$i) {
                $m = [];
                if (preg_match('/\G\s*(\d{1,10})\s+(\d{1,5})\s+([nf])/', $data, $m, flags: 0, offset: $pos) !== 1) {
                    throw new PdfException('Malformed cross-reference entry.');
                }
                $pos += strlen($m[0] ?? '');
                if ('n' === ($m[3] ?? 'f')) {
                    $table->addInFile($start + $i, (int) ($m[1] ?? 0), (int) ($m[2] ?? 0));
                }
            }
            $this->lexer->seek($pos);
        }
    }
}
