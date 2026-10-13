<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Hyphenation;

use Php2Pdf\Core\FileReader;
use Php2Pdf\Core\LineEnd;
use Php2Pdf\Exception\IoException;

use function array_fill;
use function array_key_exists;
use function array_slice;
use function count;
use function ctype_digit;
use function implode;
use function max;
use function mb_str_split;
use function mb_strlen;
use function mb_strtolower;
use function min;
use function pathinfo;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function str_replace;
use function trim;

use const PATHINFO_FILENAME;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Liang's pattern hyphenation as TeX does it. Patterns come from a pattern file in TeX or
 * hunspell "hyph_*.dic" form (one pattern per line, digits marking break weights); exceptions
 * list whole words with hyphens at the allowed breaks.
 *
 * Pattern files are not bundled: load the ones your licence allows, for example from the
 * hyph-utf8 project, with Hyphenator::fromFile().
 *
 * @api
 */
final class Hyphenator
{
    /** @var array<string, list<int>> letters => weights (one per gap, length letters + 1) */
    private array $patterns = [];

    /** @var array<string, list<int>> word => break positions */
    private array $exceptions = [];

    /** @var array<string, list<int>> memoised results */
    private array $cache = [];

    private int $maxPatternLength = 1;

    /**
     * @param list<string> $patterns   TeX patterns such as "1cr", ".ad4der"
     * @param list<string> $exceptions words with hyphens at the allowed breaks, such as "ta-ble"
     */
    public function __construct(
        array $patterns,
        array $exceptions = [],
        public readonly string $language = 'und',
    ) {
        foreach ($patterns as $pattern) {
            $this->addPattern($pattern);
        }
        foreach ($exceptions as $exception) {
            $this->addException($exception);
        }
    }

    /**
     * Loads a TeX "\patterns{...} \hyphenation{...}" file or a hunspell hyph_*.dic file.
     *
     * @throws IoException
     */
    public static function fromFile(string $path, ?string $language = null): self
    {
        return self::fromString(
            FileReader::read($path, 'hyphenation patterns'),
            $language ?? pathinfo($path, flags: PATHINFO_FILENAME),
        );
    }

    public static function fromString(string $text, string $language = 'und'): self
    {
        $text = preg_replace('/%.*$/m', replacement: '', subject: $text) ?? $text;
        [$patterns, $exceptions] = self::split($text);

        return new self($patterns, $exceptions, $language);
    }

    /**
     * The entries of every TeX "\\name{...}" block in the text.
     *
     * @return list<string>
     */
    private static function section(string $text, string $name): array
    {
        $m     = [];
        $found = preg_match_all("/\\\\{$name}\\s*\\{([^}]*)\\}/", $text, $m);
        if (false === $found || 0 === $found) {
            return [];
        }
        $entries = [];
        foreach ($m[1] ?? [] as $block) {
            foreach (self::words($block) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private static function split(string $text): array
    {
        $patterns   = self::section($text, 'patterns');
        $exceptions = self::section($text, 'hyphenation');
        if ([] !== $patterns || [] !== $exceptions) {
            return [$patterns, $exceptions];
        }
        // hunspell form: an encoding line, optional directives, then one pattern per line.
        $lines = preg_split('/' . LineEnd::PATTERN . '/', $text, flags: PREG_SPLIT_NO_EMPTY);
        foreach (false === $lines ? [] : $lines as $line) {
            $line = trim($line);
            if (
                '' === $line
                || preg_match('/^(UTF-8|ISO8859-\d|LEFTHYPHENMIN|RIGHTHYPHENMIN|COMPOUND|NOHYPHEN|NEXTLEVEL)/i', $line)
                    === 1
            ) {
                continue;
            }
            $patterns[] = $line;
        }

        return [$patterns, $exceptions];
    }

    /** @return list<string> */
    private static function words(string $block): array
    {
        $words = preg_split('/\s+/', $block, flags: PREG_SPLIT_NO_EMPTY);

        return false === $words ? [] : $words;
    }

    /**
     * Break positions (character offsets) inside a word, honouring the minimum characters that
     * must stay before and after a hyphen.
     *
     * @return list<int>
     */
    public function hyphenate(string $word, int $leftMin = 2, int $rightMin = 2): array
    {
        $key = "{$word}|{$leftMin}|{$rightMin}";
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $lower  = mb_strtolower($word, encoding: 'UTF-8');
        $chars  = mb_str_split($lower, length: 1, encoding: 'UTF-8');
        $length = count($chars);
        $breaks = $this->exceptions[$lower] ?? $this->fromPatterns($chars);
        $result = [];
        foreach ($breaks as $position) {
            if ($position < $leftMin || ($length - $position) < $rightMin) {
                continue;
            }
            $result[] = $position;
        }

        return $this->cache[$key] = $result;
    }

    /**
     * The word split at its break positions, e.g. ["hy", "phen", "ation"].
     *
     * @return list<string>
     */
    public function syllables(string $word, int $leftMin = 2, int $rightMin = 2): array
    {
        $chars  = mb_str_split($word, length: 1, encoding: 'UTF-8');
        $pieces = [];
        $start  = 0;
        foreach ([...$this->hyphenate($word, $leftMin, $rightMin), count($chars)] as $position) {
            $pieces[] = implode('', array_slice($chars, $start, $position - $start));
            $start    = $position;
        }

        return $pieces;
    }

    private function addException(string $word): void
    {
        $breaks = [];
        $offset = 0;
        foreach (mb_str_split($word, length: 1, encoding: 'UTF-8') as $char) {
            if ('-' === $char) {
                $breaks[] = $offset;
                continue;
            }
            ++$offset;
        }
        $this->exceptions[mb_strtolower(str_replace('-', replace: '', subject: $word), encoding: 'UTF-8')] = $breaks;
    }

    private function addPattern(string $pattern): void
    {
        $letters = '';
        $weights = [0];
        foreach (mb_str_split($pattern, length: 1, encoding: 'UTF-8') as $char) {
            if (ctype_digit($char)) {
                $weights[count($weights) - 1] = (int) $char;
                continue;
            }
            $letters   .= $char;
            $weights[] = 0;
        }
        if ('' === $letters) {
            return;
        }
        $this->patterns[$letters] = $weights;
        $this->maxPatternLength   = max($this->maxPatternLength, mb_strlen($letters, encoding: 'UTF-8'));
    }

    /**
     * @param list<string> $chars
     *
     * @return list<int>
     */
    private function fromPatterns(array $chars): array
    {
        $padded = ['.', ...$chars, '.'];
        $n      = count($padded);
        $points = array_fill(
            start_index: 0,
            count: $n + 1,
            value: 0,
        );
        $limit = min($this->maxPatternLength, $n);
        for ($i = 0; $i < $n; ++$i) {
            $sub = '';
            for ($len = 1; $len <= $limit && ($i + $len) <= $n; ++$len) {
                $sub     .= $padded[$i + $len - 1] ?? '';
                $weights = $this->patterns[$sub] ?? null;
                if (null === $weights) {
                    continue;
                }
                foreach ($weights as $k => $weight) {
                    if ($weight <= ($points[$i + $k] ?? 0)) {
                        continue;
                    }
                    $points[$i + $k] = $weight;
                }
            }
        }
        // points[j] sits before padded[j]; letters start at padded[1], so letter gap p is points[p + 1].
        $breaks = [];
        for ($p = 1; $p < count($chars); ++$p) {
            if (0 === (($points[$p + 1] ?? 0) % 2)) {
                continue;
            }
            $breaks[] = $p;
        }

        return $breaks;
    }
}
