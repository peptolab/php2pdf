<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use Closure;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Exception\PdfException;

use function is_float;
use function is_int;
use function is_numeric;
use function preg_match;
use function sprintf;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Builds objects from tokens: scalars, names, strings, arrays, dictionaries, references and
 * streams. Stream lengths come from the dictionary, resolved through the reader when indirect,
 * and are verified against the "endstream" keyword with a search fallback.
 *
 * @internal
 */
final class ObjectParser
{
    /** @var Closure(mixed): mixed resolves references to their objects */
    private readonly Closure $resolver;

    /** @param Closure(mixed): mixed|null $resolver */
    public function __construct(
        private readonly Lexer $lexer,
        ?Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? static fn(mixed $value): mixed => $value;
    }

    public function lexer(): Lexer
    {
        return $this->lexer;
    }

    /**
     * Parses the next object. Keywords other than true/false/null are returned as Token so
     * callers can act on "obj", "endobj", "stream" and friends.
     */
    public function parse(?Token $first = null): mixed
    {
        $token = $first ?? $this->lexer->next();

        return match ($token->type) {
            Token::NUMBER     => $this->numberOrReference($token),
            Token::STRING     => $token->value,
            Token::NAME       => new Name((string) $token->value),
            Token::ARRAY_OPEN => $this->array(),
            Token::DICT_OPEN  => $this->dictionaryOrStream(),
            Token::KEYWORD => match ($token->value) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => $token,
            },
            Token::END        => $token,
            default           => $token,
        };
    }

    /**
     * Parses "n g obj <object> endobj" at the current position and returns the object.
     *
     * @return array{0: int, 1: int, 2: mixed} object number, generation, object
     */
    public function parseIndirect(): array
    {
        $number     = $this->lexer->next();
        $generation = $this->lexer->next();
        $keyword    = $this->lexer->next();
        if (! $number->is(Token::NUMBER) || ! $generation->is(Token::NUMBER) || ! $keyword->is(Token::KEYWORD, 'obj')) {
            throw new PdfException(sprintf('Expected "n g obj" at offset %d.', $number->end));
        }
        $object = $this->parse();
        if ($object instanceof Token && $object->is(Token::KEYWORD, 'endobj')) {
            return [(int) $number->value, (int) $generation->value, null];
        }

        return [(int) $number->value, (int) $generation->value, $object];
    }

    /** @return list<mixed> */
    private function array(): array
    {
        $items = [];
        while (true) {
            $token = $this->lexer->next();
            if ($token->is(Token::ARRAY_CLOSE) || $token->is(Token::END)) {
                return $items;
            }
            if ($token->is(Token::DICT_CLOSE)) {
                continue; // stray delimiter in a broken file
            }
            $value = $this->parse($token);
            if ($value instanceof Token) {
                if ($value->is(Token::KEYWORD, 'endobj') || $value->is(Token::KEYWORD, 'endstream')) {
                    $this->lexer->seek($value->end - strlen((string) $value->value));

                    return $items;
                }
                continue;
            }
            $items[] = $value;
        }
    }

    private function dictionaryOrStream(): Dictionary|Stream
    {
        $dict = new Dictionary();
        while (true) {
            $token = $this->lexer->next();
            if ($token->is(Token::DICT_CLOSE) || $token->is(Token::END)) {
                break;
            }
            if (! $token->is(Token::NAME)) {
                // Skip a stray value so one broken entry does not lose the dictionary.
                $this->parse($token);
                continue;
            }
            $value = $this->parse();
            if ($value instanceof Token) {
                if ($value->is(Token::DICT_CLOSE)) {
                    break;
                }
                continue;
            }
            $dict->set((string) $token->value, $value);
        }

        $save  = $this->lexer->position();
        $token = $this->lexer->next();
        if (! $token->is(Token::KEYWORD, 'stream')) {
            $this->lexer->seek($save);

            return $dict;
        }

        return $this->stream($dict);
    }

    private function numberOrReference(Token $number): int|float|Reference
    {
        if (! is_int($number->value)) {
            $value = $number->value;
            if (is_float($value)) {
                return $value;
            }

            return is_numeric($value) ? (float) $value : 0.0;
        }
        // Look ahead for "g R" without committing unless it matches.
        $save       = $this->lexer->position();
        $generation = $this->lexer->next();
        if ($generation->is(Token::NUMBER) && is_int($generation->value)) {
            $r = $this->lexer->next();
            if ($r->is(Token::KEYWORD, 'R')) {
                return new Reference($number->value, $generation->value);
            }
        }
        $this->lexer->seek($save);

        return $number->value;
    }

    private function stream(Dictionary $dict): Stream
    {
        $this->lexer->skipEol();
        $start  = $this->lexer->position();
        $data   = $this->lexer->data();
        $length = ($this->resolver)($dict->get('Length'));
        $length = is_int($length) || is_float($length) ? (int) $length : -1;

        $valid = $length >= 0 && ($start + $length) <= $this->lexer->length();
        if ($valid) {
            $after = substr($data, $start + $length, length: 20);
            $valid = preg_match('/^\s*endstream/', $after) === 1;
        }
        if (! $valid) {
            // Wrong or missing /Length: measure up to the next "endstream" instead.
            $end = strpos($data, needle: 'endstream', offset: $start);
            if (false === $end) {
                throw new PdfException(sprintf('Stream at offset %d has no endstream.', $start));
            }
            $length   = $end - $start;
            $measured = substr($data, $start, $length);
            foreach (["\r\n", "\n", "\r"] as $eol) {
                if (! str_ends_with($measured, $eol)) {
                    continue;
                }
                $length -= strlen($eol);
                break;
            }
        }
        $raw = substr($data, $start, $length);
        $end = strpos($data, needle: 'endstream', offset: $start + $length);
        $this->lexer->seek(false === $end ? $start + $length : $end + 9);
        $dict->remove('Length');

        return new Stream($dict, $raw, compress: false);
    }
}
