<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use PhpPdf\Exception\PdfException;

use function array_key_exists;
use function chr;
use function hex2bin;
use function hexdec;
use function is_numeric;
use function max;
use function min;
use function octdec;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function strcspn;
use function strlen;
use function strpos;
use function strspn;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Tokenises PDF syntax from a byte string at a movable position.
 *
 * @internal
 */
final class Lexer
{
    private const string WHITESPACE = " \t\r\n\f\0";
    private const string DELIMITERS = '()<>[]{}/%';

    private int $position = 0;
    private readonly int $length;

    public function __construct(
        private readonly string $data,
    ) {
        $this->length = strlen($data);
    }

    public function data(): string
    {
        return $this->data;
    }

    public function length(): int
    {
        return $this->length;
    }

    public function next(): Token
    {
        $this->skipWhitespaceAndComments();
        if ($this->position >= $this->length) {
            return new Token(Token::END, '', $this->position);
        }
        $c = $this->data[$this->position];

        return match (true) {
            '/' === $c => $this->name(),
            '(' === $c => $this->literalString(),
            '<' === $c => '<' === ($this->data[$this->position + 1] ?? '')
                ? $this->simple(Token::DICT_OPEN, '<<', 2)
                : $this->hexString(),
            '>' === $c => '>' === ($this->data[$this->position + 1] ?? '')
                ? $this->simple(Token::DICT_CLOSE, '>>', 2)
                : throw new PdfException(sprintf('Unexpected ">" at offset %d.', $this->position)),
            '[' === $c => $this->simple(Token::ARRAY_OPEN, '[', 1),
            ']' === $c => $this->simple(Token::ARRAY_CLOSE, ']', 1),
            '{' === $c => $this->simple(Token::BRACE_OPEN, '{', 1),
            '}' === $c => $this->simple(Token::BRACE_CLOSE, '}', 1),
            ')' === $c => throw new PdfException(sprintf('Unexpected ")" at offset %d.', $this->position)),
            ('0' <= $c && $c <= '9') || '+' === $c || '-' === $c || '.' === $c => $this->number(),
            default => $this->keyword(),
        };
    }

    public function position(): int
    {
        return $this->position;
    }

    public function seek(int $position): void
    {
        $this->position = max(0, min($position, $this->length));
    }

    public function skipEol(): void
    {
        if ("\r" === ($this->data[$this->position] ?? '')) {
            ++$this->position;
        }
        if ("\n" === ($this->data[$this->position] ?? '')) {
            ++$this->position;
        }
    }

    /** Skips whitespace only, leaving the position at the next byte (used after "stream"). */
    /**
     * Skips an inline image's data, just after its ID operator, to the end of its EI operator (or
     * of the stream when there is none).
     */
    public function skipInlineImageData(): void
    {
        $match = [];
        if (preg_match('/\sEI(?=\s|$)/', $this->data, $match, PREG_OFFSET_CAPTURE, $this->position) === 1) {
            $this->seek((int) ($match[0][1] ?? 0) + 3);

            return;
        }
        $this->seek($this->length());
    }

    /**
     * Resolves a backslash escape starting at $i (the byte after the backslash).
     *
     * @return array{0: string, 1: int} the decoded bytes and the position after the escape
     */
    private function escape(int $i): array
    {
        $c = $this->data[$i] ?? '';
        if ('' === $c) {
            return ['', $i];
        }
        $simple = [
            'n'  => "\n",
            'r'  => "\r",
            't'  => "\t",
            'b'  => "\x08",
            'f'  => "\x0C",
            '('  => '(',
            ')'  => ')',
            '\\' => '\\',
        ];
        if (array_key_exists($c, $simple)) {
            return [$simple[$c], $i + 1];
        }
        if ('0' <= $c && $c <= '7') {
            $span  = strspn($this->data, characters: '01234567', offset: $i, length: 3);
            $octal = substr($this->data, $i, $span);

            return [chr(octdec($octal) & 0xFF), $i + $span];
        }
        if ("\r" === $c) {
            return ['', "\n" === ($this->data[$i + 1] ?? '') ? $i + 2 : $i + 1];
        }
        if ("\n" === $c) {
            return ['', $i + 1];
        }

        return [$c, $i + 1];
    }

    private function hexString(): Token
    {
        $start = $this->position + 1;
        $end   = strpos($this->data, needle: '>', offset: $start);
        if (false === $end) {
            throw new PdfException(sprintf('Unterminated hex string at offset %d.', $this->position));
        }
        $hex =
            preg_replace('/[^0-9A-Fa-f]/', replacement: '', subject: substr($this->data, $start, $end - $start)) ?? '';
        if (0 !== (strlen($hex) % 2)) {
            $hex .= '0';
        }
        $this->position = $end + 1;

        return new Token(Token::STRING, (string) hex2bin($hex), $this->position);
    }

    private function keyword(): Token
    {
        $start = $this->position;
        $span  = $this->tokenSpan($start);
        if (0 === $span) {
            // An unexpected delimiter; skip it so parsing can continue.
            ++$this->position;

            return $this->next();
        }
        $this->position = $start + $span;

        return new Token(Token::KEYWORD, substr($this->data, $start, $span), $this->position);
    }

    private function literalString(): Token
    {
        $depth = 0;
        $out   = '';
        $i     = $this->position;
        while ($i < $this->length) {
            $c = $this->data[$i++];
            if ('\\' === $c) {
                [$piece, $i] = $this->escape($i);
                $out .= $piece;
                continue;
            }
            if ('(' === $c) {
                if ($depth++ > 0) {
                    $out .= $c;
                }
                continue;
            }
            if (')' === $c) {
                if (--$depth === 0) {
                    $this->position = $i;

                    return new Token(Token::STRING, $out, $i);
                }
                $out .= $c;
                continue;
            }
            if ("\r" === $c) {
                // Any end-of-line inside a string becomes a single line feed.
                if ("\n" === ($this->data[$i] ?? '')) {
                    ++$i;
                }
                $out .= "\n";
                continue;
            }
            $out .= $c;
        }
        throw new PdfException(sprintf('Unterminated string at offset %d.', $this->position));
    }

    private function name(): Token
    {
        $start          = $this->position + 1;
        $span           = $this->tokenSpan($start);
        $raw            = substr($this->data, $start, $span);
        $this->position = $start + $span;
        $value          = preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/',
            /** @param array<array-key, string> $m */
            static fn(array $m): string => chr((int) hexdec($m[1] ?? '0')),
            $raw,
        ) ?? $raw;

        return new Token(Token::NAME, $value, $this->position);
    }

    private function number(): Token
    {
        $start          = $this->position;
        $span           = strspn($this->data, characters: '+-.0123456789', offset: $start);
        $text           = substr($this->data, $start, $span);
        $this->position += $span;
        if (preg_match('/^[+-]?\d+$/', $text) === 1) {
            return new Token(Token::NUMBER, (int) $text, $this->position);
        }
        // Tolerate malformed reals such as "--5" or "3.4.5" the way viewers do.
        $clean = preg_replace('/^([+-])[+-]+/', replacement: '$1', subject: $text) ?? $text;
        $clean = preg_replace('/(\.\d*)\./', replacement: '$1', subject: $clean) ?? $clean;

        return new Token(Token::NUMBER, is_numeric($clean) ? (float) $clean : 0.0, $this->position);
    }

    private function simple(int $type, string $value, int $width): Token
    {
        $this->position += $width;

        return new Token($type, $value, $this->position);
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->position < $this->length) {
            $c = $this->data[$this->position];
            if (str_contains(self::WHITESPACE, $c)) {
                ++$this->position;
                continue;
            }
            if ('%' === $c) {
                $eol            = strcspn($this->data, characters: "\r\n", offset: $this->position);
                $this->position += $eol;
                continue;
            }
            break;
        }
    }

    /** Length of a run of regular (non-delimiter, non-whitespace) characters from $start. */
    private function tokenSpan(int $start): int
    {
        return strcspn($this->data, self::WHITESPACE . self::DELIMITERS, $start);
    }
}
