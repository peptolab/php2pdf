<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Color\Rgb;

use function array_key_exists;
use function count;
use function fmod;
use function hexdec;
use function max;
use function min;
use function preg_match;
use function preg_split;
use function rad2deg;
use function rtrim;
use function str_ends_with;
use function str_repeat;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * CSS colours as SVG uses them: named, #rgb, #rgba, #rrggbb, #rrggbbaa, rgb() and rgba() with
 * numbers or percentages, hsl() and hsla(), in comma or space syntax, plus "none", "currentColor"
 * and url() paint servers. Alpha is read separately (alpha()) and applied as opacity.
 *
 * @internal
 */
final class ColorParser
{
    /** @var array<string, string> */
    private const array NAMED = [
        'aliceblue'            => 'f0f8ff',
        'antiquewhite'         => 'faebd7',
        'aqua'                 => '00ffff',
        'aquamarine'           => '7fffd4',
        'azure'                => 'f0ffff',
        'beige'                => 'f5f5dc',
        'bisque'               => 'ffe4c4',
        'black'                => '000000',
        'blanchedalmond'       => 'ffebcd',
        'blue'                 => '0000ff',
        'blueviolet'           => '8a2be2',
        'brown'                => 'a52a2a',
        'burlywood'            => 'deb887',
        'cadetblue'            => '5f9ea0',
        'chartreuse'           => '7fff00',
        'chocolate'            => 'd2691e',
        'coral'                => 'ff7f50',
        'cornflowerblue'       => '6495ed',
        'cornsilk'             => 'fff8dc',
        'crimson'              => 'dc143c',
        'cyan'                 => '00ffff',
        'darkblue'             => '00008b',
        'darkcyan'             => '008b8b',
        'darkgoldenrod'        => 'b8860b',
        'darkgray'             => 'a9a9a9',
        'darkgreen'            => '006400',
        'darkgrey'             => 'a9a9a9',
        'darkkhaki'            => 'bdb76b',
        'darkmagenta'          => '8b008b',
        'darkolivegreen'       => '556b2f',
        'darkorange'           => 'ff8c00',
        'darkorchid'           => '9932cc',
        'darkred'              => '8b0000',
        'darksalmon'           => 'e9967a',
        'darkseagreen'         => '8fbc8f',
        'darkslateblue'        => '483d8b',
        'darkslategray'        => '2f4f4f',
        'darkslategrey'        => '2f4f4f',
        'darkturquoise'        => '00ced1',
        'darkviolet'           => '9400d3',
        'deeppink'             => 'ff1493',
        'deepskyblue'          => '00bfff',
        'dimgray'              => '696969',
        'dimgrey'              => '696969',
        'dodgerblue'           => '1e90ff',
        'firebrick'            => 'b22222',
        'floralwhite'          => 'fffaf0',
        'forestgreen'          => '228b22',
        'fuchsia'              => 'ff00ff',
        'gainsboro'            => 'dcdcdc',
        'ghostwhite'           => 'f8f8ff',
        'gold'                 => 'ffd700',
        'goldenrod'            => 'daa520',
        'gray'                 => '808080',
        'green'                => '008000',
        'greenyellow'          => 'adff2f',
        'grey'                 => '808080',
        'honeydew'             => 'f0fff0',
        'hotpink'              => 'ff69b4',
        'indianred'            => 'cd5c5c',
        'indigo'               => '4b0082',
        'ivory'                => 'fffff0',
        'khaki'                => 'f0e68c',
        'lavender'             => 'e6e6fa',
        'lavenderblush'        => 'fff0f5',
        'lawngreen'            => '7cfc00',
        'lemonchiffon'         => 'fffacd',
        'lightblue'            => 'add8e6',
        'lightcoral'           => 'f08080',
        'lightcyan'            => 'e0ffff',
        'lightgoldenrodyellow' => 'fafad2',
        'lightgray'            => 'd3d3d3',
        'lightgreen'           => '90ee90',
        'lightgrey'            => 'd3d3d3',
        'lightpink'            => 'ffb6c1',
        'lightsalmon'          => 'ffa07a',
        'lightseagreen'        => '20b2aa',
        'lightskyblue'         => '87cefa',
        'lightslategray'       => '778899',
        'lightslategrey'       => '778899',
        'lightsteelblue'       => 'b0c4de',
        'lightyellow'          => 'ffffe0',
        'lime'                 => '00ff00',
        'limegreen'            => '32cd32',
        'linen'                => 'faf0e6',
        'magenta'              => 'ff00ff',
        'maroon'               => '800000',
        'mediumaquamarine'     => '66cdaa',
        'mediumblue'           => '0000cd',
        'mediumorchid'         => 'ba55d3',
        'mediumpurple'         => '9370db',
        'mediumseagreen'       => '3cb371',
        'mediumslateblue'      => '7b68ee',
        'mediumspringgreen'    => '00fa9a',
        'mediumturquoise'      => '48d1cc',
        'mediumvioletred'      => 'c71585',
        'midnightblue'         => '191970',
        'mintcream'            => 'f5fffa',
        'mistyrose'            => 'ffe4e1',
        'moccasin'             => 'ffe4b5',
        'navajowhite'          => 'ffdead',
        'navy'                 => '000080',
        'oldlace'              => 'fdf5e6',
        'olive'                => '808000',
        'olivedrab'            => '6b8e23',
        'orange'               => 'ffa500',
        'orangered'            => 'ff4500',
        'orchid'               => 'da70d6',
        'palegoldenrod'        => 'eee8aa',
        'palegreen'            => '98fb98',
        'paleturquoise'        => 'afeeee',
        'palevioletred'        => 'db7093',
        'papayawhip'           => 'ffefd5',
        'peachpuff'            => 'ffdab9',
        'peru'                 => 'cd853f',
        'pink'                 => 'ffc0cb',
        'plum'                 => 'dda0dd',
        'powderblue'           => 'b0e0e6',
        'purple'               => '800080',
        'rebeccapurple'        => '663399',
        'red'                  => 'ff0000',
        'rosybrown'            => 'bc8f8f',
        'royalblue'            => '4169e1',
        'saddlebrown'          => '8b4513',
        'salmon'               => 'fa8072',
        'sandybrown'           => 'f4a460',
        'seagreen'             => '2e8b57',
        'seashell'             => 'fff5ee',
        'sienna'               => 'a0522d',
        'silver'               => 'c0c0c0',
        'skyblue'              => '87ceeb',
        'slateblue'            => '6a5acd',
        'slategray'            => '708090',
        'slategrey'            => '708090',
        'snow'                 => 'fffafa',
        'springgreen'          => '00ff7f',
        'steelblue'            => '4682b4',
        'tan'                  => 'd2b48c',
        'teal'                 => '008080',
        'thistle'              => 'd8bfd8',
        'tomato'               => 'ff6347',
        'turquoise'            => '40e0d0',
        'violet'               => 'ee82ee',
        'wheat'                => 'f5deb3',
        'white'                => 'ffffff',
        'whitesmoke'           => 'f5f5f5',
        'yellow'               => 'ffff00',
        'yellowgreen'          => '9acd32',
    ];

    /**
     * The alpha of a colour value from 0.0 to 1.0: the alpha digits of #rgba and #rrggbbaa, or
     * the fourth argument of rgba() and hsla(); 1.0 for colours without one, paint servers and
     * anything else. currentColor takes the alpha of the color property.
     */
    public static function alpha(?string $value, ?string $currentColor = null): float
    {
        $value = strtolower(trim($value ?? ''));
        if ('currentcolor' === $value) {
            return null === $currentColor || 'currentcolor' === strtolower(trim($currentColor))
                ? 1.0
                : self::alpha($currentColor);
        }
        if ('' !== $value && '#' === $value[0] && preg_match('/^#([0-9a-f]{4}|[0-9a-f]{8})$/', $value) === 1) {
            $digits = 5 === strlen($value) ? str_repeat($value[4], times: 2) : substr($value, offset: 7, length: 2);

            return hexdec($digits) / 255;
        }
        $m = [];
        if (preg_match('/^(?:rgb|hsl)a?\(([^)]*)\)$/', $value, $m) !== 1) {
            return 1.0;
        }
        $arguments = self::arguments($m[1] ?? '');
        $alpha     = $arguments[3] ?? null;
        if (null === $alpha) {
            return 1.0;
        }

        return max(0.0, min(
            1.0,
            str_ends_with($alpha, '%')
                ? Length::toFloat(rtrim($alpha, characters: '%')) / 100.0
                : Length::toFloat($alpha),
        ));
    }

    /** A colour value, or null when the text is not a colour. */
    public static function color(?string $value, ?Rgb $currentColor = null): ?Rgb
    {
        if (null === $value) {
            return null;
        }
        $value = strtolower(trim($value));
        if ('currentcolor' === $value) {
            return $currentColor;
        }
        if (array_key_exists($value, self::NAMED)) {
            return Rgb::fromHex(self::NAMED[$value]);
        }
        if ('' !== $value && '#' === $value[0]) {
            return self::hex(substr($value, offset: 1));
        }
        $m = [];
        if (preg_match('/^rgba?\(\s*([^)]*)\)$/', $value, $m) === 1) {
            return self::rgb($m[1] ?? '');
        }
        if (preg_match('/^hsla?\(\s*([^)]*)\)$/', $value, $m) === 1) {
            return self::hsl($m[1] ?? '');
        }

        return null;
    }

    /** A fill or stroke value: none, a colour, or url(#id) with an optional fallback. */
    public static function paint(?string $value, ?Rgb $currentColor = null): ?Paint
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if ('none' === strtolower($value)) {
            return Paint::none();
        }
        $url = ElementRef::splitUrl($value);
        if (null !== $url) {
            return Paint::server($url[0], '' === $url[1] ? null : self::color($url[1], $currentColor));
        }
        $color = self::color($value, $currentColor);

        return null === $color ? null : Paint::color($color);
    }

    /**
     * The arguments of a colour function, separated by commas, spaces or a slash before alpha.
     *
     * @return list<string>
     */
    private static function arguments(string $args): array
    {
        $parts = preg_split('/\s*[,\/]\s*|\s+/', trim($args), flags: PREG_SPLIT_NO_EMPTY);

        return false === $parts ? [] : $parts;
    }

    private static function hex(string $hex): ?Rgb
    {
        $length = strlen($hex);
        if (4 === $length || 8 === $length) {
            $hex = substr($hex, offset: 0, length: $length - ($length / 4));
        }
        if (3 !== strlen($hex) && 6 !== strlen($hex)) {
            return null;
        }
        if (preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            return null;
        }

        return Rgb::fromHex($hex);
    }

    /** hsl() and hsla(): hue in degrees (or with deg, turn, rad), saturation and lightness in percent. */
    private static function hsl(string $args): ?Rgb
    {
        $parts = self::arguments($args);
        if (count($parts) < 3) {
            return null;
        }
        $hue = $parts[0];
        $h   = match (true) {
            str_ends_with($hue, 'turn') => Length::toFloat(substr($hue, offset: 0, length: -4)) * 360.0,
            str_ends_with($hue, 'rad')  => rad2deg(Length::toFloat(substr($hue, offset: 0, length: -3))),
            str_ends_with($hue, 'deg')  => Length::toFloat(substr($hue, offset: 0, length: -3)),
            default                     => Length::toFloat($hue),
        };
        $h = fmod(fmod($h, num2: 360.0) + 360.0, num2: 360.0) / 360.0;
        $s = max(0.0, min(1.0, Length::toFloat(rtrim($parts[1] ?? '0', characters: '%')) / 100.0));
        $l = max(0.0, min(1.0, Length::toFloat(rtrim($parts[2] ?? '0', characters: '%')) / 100.0));
        $q = $l < 0.5 ? $l * (1.0 + $s) : $l + $s - ($l * $s);
        $p = (2.0 * $l) - $q;

        return new Rgb(self::hue($p, $q, $h + (1 / 3)), self::hue($p, $q, $h), self::hue($p, $q, $h - (1 / 3)));
    }

    /** One channel of an HSL colour, from the CSS Color specification. */
    private static function hue(float $p, float $q, float $t): float
    {
        if ($t < 0.0) {
            $t += 1.0;
        }
        if ($t > 1.0) {
            $t -= 1.0;
        }

        return match (true) {
            $t < (1 / 6) => $p + (($q - $p) * 6.0 * $t),
            $t < 0.5 => $q,
            $t < (2 / 3) => $p + (($q - $p) * ((2 / 3) - $t) * 6.0),
            default => $p,
        };
    }

    private static function rgb(string $args): ?Rgb
    {
        $parts = self::arguments($args);
        if (count($parts) < 3) {
            return null;
        }
        $channel = static function (string $part): float {
            $number = Length::toFloat(rtrim($part, characters: '%'));

            return max(0.0, min(1.0, str_ends_with($part, '%') ? $number / 100.0 : $number / 255.0));
        };

        return new Rgb($channel($parts[0]), $channel($parts[1] ?? '0'), $channel($parts[2] ?? '0'));
    }
}
