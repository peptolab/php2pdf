<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Canvas\LineCap;
use Php2Pdf\Canvas\LineJoin;
use Php2Pdf\Color\Rgb;

use function array_key_exists;
use function count;
use function explode;
use function is_numeric;
use function max;
use function min;
use function preg_match;
use function str_ends_with;
use function strtolower;
use function substr;
use function trim;

/**
 * The computed style of an element: inherited properties from its ancestors, then presentation
 * attributes, the style sheet, and the inline style attribute, in increasing precedence.
 *
 * @internal
 */
final class Style
{
    /** @var list<string> */
    private const array INHERITED = [
        'fill',
        'fill-opacity',
        'fill-rule',
        'stroke',
        'stroke-width',
        'stroke-opacity',
        'stroke-linecap',
        'stroke-linejoin',
        'stroke-miterlimit',
        'stroke-dasharray',
        'stroke-dashoffset',
        'font-family',
        'font-size',
        'font-weight',
        'font-style',
        'text-anchor',
        'letter-spacing',
        'word-spacing',
        'color',
        'visibility',
        'clip-rule',
        'direction',
        'marker-start',
        'marker-mid',
        'marker-end',
    ];

    /** The properties the marker shorthand sets. */
    private const array MARKERS = ['marker-start', 'marker-mid', 'marker-end'];

    /** @var list<string> */
    private const array PRESENTATION = [
        ...self::INHERITED,
        'opacity',
        'clip-path',
        'display',
        'stop-color',
        'stop-opacity',
        'overflow',
        'mask',
        'mask-type',
        'filter',
    ];

    /** @param array<string, string> $properties */
    private function __construct(
        private readonly array $properties,
    ) {}

    public static function root(): self
    {
        return new self([
            'fill'        => 'black',
            'font-size'   => '16',
            'font-family' => 'sans-serif',
        ]);
    }

    private static function keywordFontSize(string $keyword, float $parent): float
    {
        $keyword = strtolower(trim($keyword));

        return match ($keyword) {
            'xx-small' => 9.0,
            'x-small'  => 10.0,
            'small'    => 13.0,
            'medium'   => 16.0,
            'large'    => 18.0,
            'x-large'  => 24.0,
            'xx-large' => 32.0,
            'smaller'  => $parent / 1.2,
            'larger'   => $parent * 1.2,
            default    => $parent,
        };
    }

    private static function opacityValue(?string $value): float
    {
        if (null === $value) {
            return 1.0;
        }
        $value = trim($value);
        $scale = 1.0;
        if (str_ends_with($value, '%')) {
            $value = substr($value, offset: 0, length: -1);
            $scale = 100.0;
        }

        return is_numeric($value) ? max(0.0, min(1.0, (float) $value / $scale)) : 1.0;
    }

    public function clipPathId(): ?string
    {
        $m = [];
        if (preg_match('/url\(\s*["\']?#([^"\')]+)["\']?\s*\)/', $this->properties['clip-path'] ?? '', $m) !== 1) {
            return null;
        }

        return $m[1] ?? null;
    }

    public function clipRule(): FillRule
    {
        return 'evenodd' === strtolower(trim($this->properties['clip-rule'] ?? ''))
            ? FillRule::EvenOdd
            : FillRule::NonZero;
    }

    public function color(): ?Rgb
    {
        return ColorParser::color($this->properties['color'] ?? null);
    }

    /** @return list<float> */
    public function dashArray(): array
    {
        $value = strtolower(trim($this->properties['stroke-dasharray'] ?? 'none'));
        if ('none' === $value || '' === $value) {
            return [];
        }
        $numbers = [];
        foreach (Length::numbers($value) as $n) {
            if ($n < 0.0) {
                return [];
            }
            $numbers[] = $n;
        }
        if (0 !== (count($numbers) % 2)) {
            $numbers = [...$numbers, ...$numbers];
        }
        $sum = 0.0;
        foreach ($numbers as $n) {
            $sum += $n;
        }

        return $sum <= 0.0 ? [] : $numbers;
    }

    public function dashOffset(): float
    {
        return Length::parse($this->properties['stroke-dashoffset'] ?? null, 0.0, $this->fontSize()) ?? 0.0;
    }

    /** The style of a child element, given its parent's computed style. */
    public function derive(Element $element, StyleSheet $sheet): self
    {
        /** @var array<string, string> $properties */
        $properties = [];
        foreach (self::INHERITED as $name) {
            if (! array_key_exists($name, $this->properties)) {
                continue;
            }
            $properties[$name] = $this->properties[$name];
        }
        foreach (self::PRESENTATION as $name) {
            $value = $element->attribute($name);
            if (null === $value) {
                continue;
            }
            $properties[$name] = $value;
        }
        foreach ([$sheet->match($element), StyleSheet::declarations($element->attribute('style') ?? '')] as $rules) {
            foreach ($rules as $name => $value) {
                foreach ('marker' === $name ? self::MARKERS : [$name] as $longhand) {
                    $properties[$longhand] = $value;
                }
            }
        }
        foreach ($properties as $name => $value) {
            if ('inherit' !== trim($value)) {
                continue;
            }
            $inherited = $this->properties[$name] ?? null;
            if (null === $inherited) {
                unset($properties[$name]);
                continue;
            }
            $properties[$name] = $inherited;
        }
        $fontSize = Length::parse($properties['font-size'] ?? null, $this->fontSize(), $this->fontSize());
        if (null !== $fontSize) {
            $properties['font-size'] = (string) $fontSize;
        }
        if (null === $fontSize && array_key_exists('font-size', $properties)) {
            $properties['font-size'] = (string) self::keywordFontSize($properties['font-size'], $this->fontSize());
        }

        return new self($properties);
    }

    public function fill(): Paint
    {
        return ColorParser::paint($this->properties['fill'] ?? 'black', $this->color()) ?? Paint::none();
    }

    public function fillOpacity(): float
    {
        return (
            self::opacityValue($this->properties['fill-opacity'] ?? null)
                * ColorParser::alpha($this->properties['fill'] ?? null, $this->properties['color'] ?? null)
        );
    }

    public function fillRule(): FillRule
    {
        return 'evenodd' === strtolower(trim($this->properties['fill-rule'] ?? ''))
            ? FillRule::EvenOdd
            : FillRule::NonZero;
    }

    /** @return list<string> */
    public function fontFamilies(): array
    {
        $out = [];
        foreach (explode(',', $this->properties['font-family'] ?? 'sans-serif') as $family) {
            $family = trim($family, characters: " \t\"'");
            if ('' === $family) {
                continue;
            }
            $out[] = $family;
        }

        return $out;
    }

    public function fontSize(): float
    {
        $value = $this->properties['font-size'] ?? '16';

        return is_numeric($value) ? (float) $value : 16.0;
    }

    public function get(string $name): ?string
    {
        return $this->properties[$name] ?? null;
    }

    public function isBold(): bool
    {
        $weight = strtolower(trim($this->properties['font-weight'] ?? 'normal'));
        if (is_numeric($weight)) {
            return (float) $weight >= 600.0;
        }

        return 'bold' === $weight || 'bolder' === $weight;
    }

    public function isDisplayed(): bool
    {
        return 'none' !== strtolower(trim($this->properties['display'] ?? ''));
    }

    public function isItalic(): bool
    {
        $style = strtolower(trim($this->properties['font-style'] ?? 'normal'));

        return 'italic' === $style || 'oblique' === $style;
    }

    public function isVisible(): bool
    {
        $visibility = strtolower(trim($this->properties['visibility'] ?? 'visible'));

        return 'hidden' !== $visibility && 'collapse' !== $visibility;
    }

    public function letterSpacing(): float
    {
        return Length::parse($this->properties['letter-spacing'] ?? null, 0.0, $this->fontSize()) ?? 0.0;
    }

    public function lineCap(): LineCap
    {
        return match (strtolower(trim($this->properties['stroke-linecap'] ?? 'butt'))) {
            'round'  => LineCap::Round,
            'square' => LineCap::Square,
            default  => LineCap::Butt,
        };
    }

    public function lineJoin(): LineJoin
    {
        return match (strtolower(trim($this->properties['stroke-linejoin'] ?? 'miter'))) {
            'round' => LineJoin::Round,
            'bevel' => LineJoin::Bevel,
            default => LineJoin::Miter,
        };
    }

    /** The marker for 'start', 'mid' or 'end' vertices, or null. */
    public function markerId(string $position): ?string
    {
        $m = [];
        if (
            preg_match('/url\(\s*["\']?#([^"\')]+)["\']?\s*\)/', $this->properties["marker-{$position}"] ?? '', $m)
            !== 1
        ) {
            return null;
        }

        return $m[1] ?? null;
    }

    /** Whether a <mask> masks by alpha (mask-type="alpha") rather than luminance. */
    public function maskAlpha(): bool
    {
        return 'alpha' === strtolower(trim($this->properties['mask-type'] ?? ''));
    }

    /** The id of the mask the element is drawn through, from mask: url(#id). */
    public function maskId(): ?string
    {
        $m = [];
        if (preg_match('/url\(\s*["\']?#([^"\')]+)["\']?\s*\)/', $this->properties['mask'] ?? '', $m) !== 1) {
            return null;
        }

        return $m[1] ?? null;
    }

    public function miterLimit(): float
    {
        $value = $this->properties['stroke-miterlimit'] ?? '4';

        return is_numeric($value) ? max(1.0, (float) $value) : 4.0;
    }

    public function opacity(): float
    {
        return self::opacityValue($this->properties['opacity'] ?? null);
    }

    public function overflowVisible(): bool
    {
        $overflow = strtolower(trim($this->properties['overflow'] ?? 'hidden'));

        return 'visible' === $overflow || 'auto' === $overflow;
    }

    public function stopColor(): Rgb
    {
        return ColorParser::color($this->properties['stop-color'] ?? 'black', $this->color()) ?? Rgb::fromHex('000000');
    }

    public function stopOpacity(): float
    {
        return (
            self::opacityValue($this->properties['stop-opacity'] ?? null)
                * ColorParser::alpha($this->properties['stop-color'] ?? null, $this->properties['color'] ?? null)
        );
    }

    public function stroke(): Paint
    {
        return ColorParser::paint($this->properties['stroke'] ?? 'none', $this->color()) ?? Paint::none();
    }

    public function strokeOpacity(): float
    {
        return (
            self::opacityValue($this->properties['stroke-opacity'] ?? null)
                * ColorParser::alpha($this->properties['stroke'] ?? null, $this->properties['color'] ?? null)
        );
    }

    /** The stroke width in user units; a percentage resolves against the normalised viewport diagonal. */
    public function strokeWidth(float $viewportDiagonal): float
    {
        return Length::parse($this->properties['stroke-width'] ?? null, $viewportDiagonal, $this->fontSize()) ?? 1.0;
    }

    /** 0.0 for start, 0.5 for middle, 1.0 for end. */
    public function textAnchor(): float
    {
        return match (strtolower(trim($this->properties['text-anchor'] ?? 'start'))) {
            'middle' => 0.5,
            'end'    => 1.0,
            default  => 0.0,
        };
    }

    /**
     * The features this converter does not support that the element asks for (filters).
     *
     * @return list<string>
     */
    public function unsupported(): array
    {
        $value = strtolower(trim($this->properties['filter'] ?? 'none'));

        return 'none' === $value || '' === $value ? [] : ['filter'];
    }
}
