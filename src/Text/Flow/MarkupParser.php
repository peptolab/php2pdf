<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Exception\ParseException;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\TextFormat;

use function array_splice;
use function count;
use function html_entity_decode;
use function in_array;
use function is_numeric;
use function preg_match;
use function preg_split;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * A small inline markup: <p style="name">, <span style="name">, <b>, <i>, <u>, <s>, <sup>,
 * <sub>, <br>, <tab>, <columnbreak>, <pagebreak>, <img src="name" width height raise>, and the entities &lt; &gt; &amp; &quot;
 * &nbsp; &shy; &#NNN; &#xHH;. Outside <p> elements, blank lines separate paragraphs.
 *
 * @internal
 */
final class MarkupParser
{
    private const array TAGS = [
        'p',
        'br',
        'tab',
        'columnbreak',
        'pagebreak',
        'span',
        'b',
        'i',
        'u',
        's',
        'sup',
        'sub',
        'img',
    ];

    /** @var list<array{style: ?TextStyle, spans: list<array{0: string, 1: TextStyle}>, breaks: list<array{0: int, 1: ItemKind|Item}>}> */
    private array $paragraphs = [];

    /** @var list<array{0: string, 1: TextStyle}> */
    private array $spans = [];

    /** @var list<array{0: int, 1: ItemKind|Item}> break items at span index */
    private array $breaks = [];

    private ?TextStyle $paragraphStyle = null;

    /** @var list<array{0: string, 1: TextStyle}> tag name and the style in force before it */
    private array $stack = [];

    private TextStyle $current;

    private bool $inParagraph = false;

    /** @param TextFormat $format StrictMarkup makes an unknown tag an error */
    public function __construct(
        private readonly StyleResolver $resolver,
        private readonly TextStyle $base,
        private readonly TextFormat $format = TextFormat::Markup,
    ) {
        $this->current = $resolver->resolve($base);
    }

    private static function decodeEntities(string $text): string
    {
        $text = str_replace(['&nbsp;', '&shy;'], ["\u{00A0}", "\u{00AD}"], $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');
        if (str_contains($text, '&') && preg_match('/&#\d+;|&#x[0-9a-f]+;/i', $text) === 1) {
            throw new ParseException('Unsupported character reference in text markup.');
        }

        return $text;
    }

    /**
     * @return list<array{0: ?TextStyle, 1: list<array{0: string, 1: TextStyle}>, 2: list<array{0: int, 1: ItemKind|Item}>}>
     *         per paragraph: paragraph style override, spans, and breaks positioned before the span at that index
     */
    public function parse(string $markup): array
    {
        $parts = preg_split('/(<\/?[a-zA-Z][a-zA-Z0-9]*(?:\s[^>]*)?>)/', $markup, flags: PREG_SPLIT_DELIM_CAPTURE);
        foreach (false === $parts ? [] : $parts as $part) {
            if ('' === $part) {
                continue;
            }
            if ('<' === $part[0] && str_ends_with($part, '>')) {
                $this->tag($part);
                continue;
            }
            $this->text($part);
        }
        $this->flushParagraph();

        $out = [];
        foreach ($this->paragraphs as $paragraph) {
            $out[] = [$paragraph['style'], $paragraph['spans'], $paragraph['breaks']];
        }

        return $out;
    }

    private function break(ItemKind $kind): void
    {
        $this->breaks[] = [count($this->spans), $kind];
    }

    private function close(string $name): void
    {
        if ('p' === $name) {
            $this->flushParagraph();

            return;
        }
        for ($i = count($this->stack) - 1; $i >= 0; --$i) {
            $entry = $this->stack[$i] ?? null;
            if (null === $entry || $entry[0] !== $name) {
                continue;
            }
            $this->current = $entry[1];
            array_splice($this->stack, $i);

            return;
        }
    }

    private function flushParagraph(): void
    {
        if ([] !== $this->spans || [] !== $this->breaks) {
            $this->paragraphs[] = [
                'style'  => $this->paragraphStyle,
                'spans'  => $this->spans,
                'breaks' => $this->breaks,
            ];
        }
        $this->spans          = [];
        $this->breaks         = [];
        $this->inParagraph    = false;
        $this->paragraphStyle = null;
        $this->stack          = [];
        $this->current        = $this->resolver->resolve($this->base);
    }

    /** An inline image: <img src="name"> with optional width, height and raise in points. */
    private function image(string $inner): void
    {
        $attribute = static function (string $key) use ($inner): ?string {
            $m = [];

            return preg_match("/\\b{$key}\\s*=\\s*[\"']([^\"']*)[\"']/i", $inner, $m) === 1 ? $m[1] ?? null : null;
        };
        $src = $attribute('src') ?? throw new ParseException(
            'An <img> in text markup needs a src naming an inline object.',
        );
        $number = static function (string $key) use ($attribute): ?float {
            $value = $attribute($key);

            return is_numeric($value) ? (float) $value : null;
        };
        $this->breaks[] = [
            count($this->spans),
            Item::inline(
                InlineObject::sized(
                    $this->resolver->inline($src),
                    $number('width'),
                    $number('height'),
                    $number('raise') ?? 0.0,
                    $this->current->fontSize(),
                ),
                $this->current,
            ),
        ];
    }

    private function isOpen(string $name): bool
    {
        foreach ($this->stack as [$open]) {
            if ($open === $name) {
                return true;
            }
        }

        return false;
    }

    private function open(string $name, ?string $styleName): void
    {
        $this->stack[] = [$name, $this->current];
        $override      = null === $styleName ? null : $this->resolver->named($styleName);
        $this->current = $this->resolver
            ->resolve($this->current, $override)
            ->merge(match ($name) {
                'b' => new TextStyle(
                    font: $this->current->bold ?? $this->current->font,
                    bold: null,
                ),
                'i'     => new TextStyle(font: $this->current->italic ?? $this->current->font),
                'u'     => new TextStyle(underline: true),
                's'     => new TextStyle(strikeout: true),
                'sup' => new TextStyle(
                    size: $this->current->fontSize() * 0.65,
                    rise: $this->current->fontSize() * 0.35,
                ),
                'sub' => new TextStyle(
                    size: $this->current->fontSize() * 0.65,
                    rise: -$this->current->fontSize() * 0.15,
                ),
                default => new TextStyle(),
            });
        if ('b' === $name && null !== $this->current->boldItalic && $this->isOpen('i')) {
            $this->current = $this->current->merge(new TextStyle(font: $this->current->boldItalic));
        }
        if ('i' === $name && null !== $this->current->boldItalic && $this->isOpen('b')) {
            $this->current = $this->current->merge(new TextStyle(font: $this->current->boldItalic));
        }
    }

    private function openParagraph(?string $style): void
    {
        $this->flushParagraph();
        $this->inParagraph    = true;
        $this->paragraphStyle = null === $style ? null : $this->resolver->named($style);
        $this->current        = $this->resolver->resolve($this->base, $this->paragraphStyle);
    }

    private function tag(string $tag): void
    {
        $closing = str_starts_with($tag, '</');
        $inner   = trim(substr($tag, $closing ? 2 : 1, -1));
        $nm      = [];
        $name    = preg_match('/^[a-zA-Z][a-zA-Z0-9]*/', $inner, $nm) === 1 ? strtolower($nm[0] ?? '') : '';
        $m       = [];
        $style   = preg_match('/style\s*=\s*["\']([^"\']*)["\']/i', $inner, $m) === 1 ? $m[1] ?? null : null;

        if (! in_array($name, self::TAGS, strict: true)) {
            $this->unknown($tag);

            return;
        }
        if ($closing) {
            $this->close($name);

            return;
        }
        match ($name) {
            'p'           => $this->openParagraph($style),
            'br'          => $this->break(ItemKind::LineBreak),
            'tab'         => $this->break(ItemKind::Tab),
            'columnbreak' => $this->break(ItemKind::ColumnBreak),
            'pagebreak'   => $this->break(ItemKind::PageBreak),
            'img'         => $this->image($inner),
            default       => $this->open($name, $style),
        };
    }

    private function text(string $raw): void
    {
        $text = self::decodeEntities($raw);
        if (! $this->inParagraph) {
            // Plain-text mode: blank lines separate paragraphs, single newlines are spaces.
            $split  = preg_split('/\n[ \t]*\n/', $text);
            $chunks = false === $split ? [$text] : $split;
            foreach ($chunks as $i => $chunk) {
                if ($i > 0) {
                    $this->flushParagraph();
                }
                $chunk = str_replace("\n", replace: ' ', subject: $chunk);
                if ('' !== trim($chunk) || [] !== $this->spans) {
                    $this->spans[] = [$chunk, $this->current];
                }
            }

            return;
        }
        $this->spans[] = [str_replace("\n", replace: ' ', subject: $text), $this->current];
    }

    /** An unknown tag is dropped, or an error in strict markup. */
    private function unknown(string $tag): void
    {
        if (TextFormat::StrictMarkup === $this->format) {
            throw new ParseException(sprintf(
                'Unknown tag %s in text markup; escape text with Markup::escape().',
                $tag,
            ));
        }
    }
}
