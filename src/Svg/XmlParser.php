<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Exception\PdfException;

use function array_pop;
use function count;
use function hexdec;
use function html_entity_decode;
use function mb_chr;
use function preg_match;
use function preg_match_all;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function strrpos;
use function substr;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * A small, dependency-free XML parser sufficient for SVG: elements, attributes, text, CDATA,
 * comments, processing instructions, a DOCTYPE with an internal subset, and character and
 * named entities. Namespace prefixes on element names are dropped; attribute names keep
 * theirs ("xlink:href").
 *
 * @internal
 */
final class XmlParser
{
    private const string NAME = '[A-Za-z_:][-A-Za-z0-9_.:]*';

    private int $position = 0;
    private readonly int $length;

    private function __construct(
        private readonly string $xml,
    ) {
        $this->length = strlen($xml);
    }

    public static function decodeEntities(string $text): string
    {
        if (! str_contains($text, '&')) {
            return $text;
        }
        $text =
            preg_replace_callback(
                '/&#(x[0-9A-Fa-f]+|[0-9]+);/',
                /** @param array<array-key, string> $m */
                static function (array $m): string {
                    $ref  = $m[1] ?? '0';
                    $code = 'x' === $ref[0] ? (int) hexdec(substr($ref, offset: 1)) : (int) $ref;
                    $char = mb_chr($code, encoding: 'UTF-8');

                    return false === $char ? '' : $char;
                },
                $text,
            ) ?? $text;

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
    }

    public static function parse(string $xml): Element
    {
        $parser = new self($xml);

        return $parser->document();
    }

    private static function localName(string $name): string
    {
        $colon = strrpos($name, needle: ':');

        return false === $colon ? $name : substr($name, offset: $colon + 1);
    }

    private static function startsWithAt(string $haystack, string $needle, int $offset): bool
    {
        return substr($haystack, $offset, strlen($needle)) === $needle;
    }

    /** @return array<string, string> */
    private function attributes(string $source): array
    {
        $attributes = [];
        $m          = [];
        $found      = preg_match_all('/(' . self::NAME . ')\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $source, $m);
        if (false === $found || 0 === $found) {
            return $attributes;
        }
        $doubles = $m[2] ?? [];
        $singles = $m[3] ?? [];
        foreach ($m[1] ?? [] as $i => $name) {
            $value = '' === ($doubles[$i] ?? '') ? $singles[$i] ?? '' : $doubles[$i] ?? '';
            // Attribute values normalise line ends to spaces before entity decoding.
            $attributes[$name] = self::decodeEntities(str_replace(
                search: ["\r\n", "\r", "\n", "\t"],
                replace: ' ',
                subject: $value,
            ));
        }

        return $attributes;
    }

    private function document(): Element
    {
        /** @var list<Element> $stack */
        $stack = [];
        $root  = null;
        while ($this->position < $this->length) {
            $open = strpos($this->xml, needle: '<', offset: $this->position);
            if (false === $open) {
                $this->text(self::decodeEntities(substr($this->xml, $this->position)), $stack);
                break;
            }
            if ($open > $this->position) {
                $this->text(self::decodeEntities(substr($this->xml, $this->position, $open - $this->position)), $stack);
            }
            $this->position = $open;
            $element        = $this->markup($stack);
            if (null !== $element && [] === $stack) {
                $root ??= $element;
            }
        }
        if (null === $root) {
            throw new PdfException('SVG document has no root element.');
        }

        return $root;
    }

    /**
     * Handles the markup construct at the current position; returns an element it closed at the
     * top level, so the root can be found.
     *
     * @param list<Element> $stack
     */
    private function markup(array &$stack): ?Element
    {
        $xml = $this->xml;
        if (self::startsWithAt($xml, '<!--', $this->position)) {
            $this->skipTo('-->');

            return null;
        }
        if (self::startsWithAt($xml, '<![CDATA[', $this->position)) {
            $end = strpos($xml, needle: ']]>', offset: $this->position);
            if (false === $end) {
                throw new PdfException('Unterminated CDATA section.');
            }
            $this->text(substr($xml, $this->position + 9, $end - $this->position - 9), $stack);
            $this->position = $end + 3;

            return null;
        }
        if (self::startsWithAt($xml, '<?', $this->position)) {
            $this->skipTo('?>');

            return null;
        }
        if (self::startsWithAt($xml, '<!', $this->position)) {
            $this->skipDeclaration();

            return null;
        }
        if (self::startsWithAt($xml, '</', $this->position)) {
            $this->skipTo('>');
            $closed = array_pop($stack);

            return [] === $stack ? $closed : null;
        }

        return $this->startTag($stack);
    }

    private function skipDeclaration(): void
    {
        // A DOCTYPE may carry an internal subset in brackets, which can itself contain ">".
        $bracket = strpos($this->xml, needle: '[', offset: $this->position);
        $close   = strpos($this->xml, needle: '>', offset: $this->position);
        if (false !== $bracket && false !== $close && $bracket < $close) {
            $end = strpos($this->xml, needle: ']', offset: $bracket);
            if (false === $end) {
                throw new PdfException('Unterminated DOCTYPE.');
            }
            $close = strpos($this->xml, needle: '>', offset: $end);
        }
        if (false === $close) {
            throw new PdfException('Unterminated declaration.');
        }
        $this->position = $close + 1;
    }

    private function skipTo(string $terminator): void
    {
        $end = strpos($this->xml, $terminator, $this->position);
        if (false === $end) {
            throw new PdfException(sprintf('Unterminated markup at offset %d.', $this->position));
        }
        $this->position = $end + strlen($terminator);
    }

    /** @param list<Element> $stack */
    private function startTag(array &$stack): ?Element
    {
        $m = [];
        if (
            preg_match(
                '/\G<(' . self::NAME . ')((?:\s+' . self::NAME . '\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>/',
                $this->xml,
                $m,
                offset: $this->position,
            ) !== 1
        ) {
            throw new PdfException(sprintf('Malformed tag at offset %d.', $this->position));
        }
        $this->position += strlen($m[0] ?? '');
        $element        = new Element(self::localName($m[1] ?? ''), $this->attributes($m[2] ?? ''));
        $parent         = $stack[count($stack) - 1] ?? null;
        if (null !== $parent) {
            $parent->children[] = $element;
        }
        if ('/' === ($m[3] ?? '')) {
            return [] === $stack ? $element : null;
        }
        $stack[] = $element;

        return null;
    }

    /** @param list<Element> $stack */
    private function text(string $text, array $stack): void
    {
        $parent = $stack[count($stack) - 1] ?? null;
        if (null === $parent || '' === $text) {
            return;
        }
        $parent->children[] = $text;
    }
}
