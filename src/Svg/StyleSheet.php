<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use function array_key_exists;
use function count;
use function explode;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use function usort;

use const PREG_SPLIT_NO_EMPTY;

/**
 * The rules of a document's <style> elements. Supports type, class, id and universal selectors,
 * compound selectors ("rect.frame#main") and comma lists; combinators and pseudo-classes are
 * not matched. At-rules are skipped.
 *
 * @internal
 */
final class StyleSheet
{
    /** @var list<array{0: string, 1: list<string>, 2: ?string, 3: int, 4: int, 5: array<string, string>}> tag, classes, id, specificity, order, declarations */
    private array $rules = [];

    /**
     * Parses "name: value; name: value" into a map of lower-case names.
     *
     * @return array<string, string>
     */
    public static function declarations(string $text): array
    {
        $out = [];
        foreach (explode(';', $text) as $declaration) {
            $colon = strpos($declaration, needle: ':');
            if (false === $colon) {
                continue;
            }
            $name  = strtolower(trim(substr($declaration, offset: 0, length: $colon)));
            $value = trim(substr($declaration, offset: $colon + 1));
            $value = preg_replace('/\s*!important\s*$/i', replacement: '', subject: $value) ?? $value;
            if ('' === $name || '' === $value) {
                continue;
            }
            $out[$name] = $value;
        }

        return $out;
    }

    public static function fromCss(string $css): self
    {
        $sheet = new self();
        $css   = preg_replace('#/\*.*?\*/#s', replacement: '', subject: $css) ?? $css;
        $css   = self::stripAtRules($css);
        $m     = [];
        $found = preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m);
        if (false === $found || 0 === $found) {
            return $sheet;
        }
        $bodies = $m[2] ?? [];
        foreach ($m[1] ?? [] as $i => $selectors) {
            $declarations = self::declarations($bodies[$i] ?? '');
            foreach (explode(',', $selectors) as $selector) {
                $sheet->addRule(trim($selector), $declarations);
            }
        }

        return $sheet;
    }

    private static function atRuleEnd(string $css, int $at): int
    {
        $n     = strlen($css);
        $depth = 0;
        for ($i = $at; $i < $n; ++$i) {
            $c = $css[$i];
            if (';' === $c && 0 === $depth) {
                return $i + 1;
            }
            if ('{' === $c) {
                ++$depth;
                continue;
            }
            if ('}' === $c) {
                --$depth;
                if ($depth <= 0) {
                    return $i + 1;
                }
            }
        }

        return $n;
    }

    /**
     * @param list<string>        $wantClasses
     * @param array<string, true> $have
     */
    private static function matches(
        string $tag,
        array $wantClasses,
        ?string $wantId,
        string $name,
        array $have,
        ?string $id,
    ): bool {
        if ('*' !== $tag && $name !== $tag) {
            return false;
        }
        if (null !== $wantId && $wantId !== $id) {
            return false;
        }
        foreach ($wantClasses as $class) {
            if (! array_key_exists($class, $have)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A simple selector as tag, classes and id; null for combinators, pseudo-classes and
     * attribute selectors, which are not supported.
     *
     * @return array{0: string, 1: list<string>, 2: ?string}|null
     */
    private static function simpleSelector(string $selector): ?array
    {
        $m = [];
        if ('' === $selector || preg_match('/^([a-zA-Z][\w-]*|\*)?((?:[.#][\w-]+)*)$/', $selector, $m) !== 1) {
            return null;
        }
        $tag     = '' === ($m[1] ?? '') || '*' === ($m[1] ?? '') ? '*' : strtolower($m[1] ?? '');
        $classes = [];
        $id      = null;
        $parts   = [];
        $found   = preg_match_all('/([.#])([\w-]+)/', $m[2] ?? '', $parts);
        $names   = $parts[2] ?? [];
        if (false !== $found && $found > 0) {
            foreach ($parts[1] ?? [] as $i => $kind) {
                if ('#' === $kind) {
                    $id = $names[$i] ?? null;
                    continue;
                }
                $classes[] = $names[$i] ?? '';
            }
        }

        return [$tag, $classes, $id];
    }

    /** Removes @-rules and their blocks (media queries, font faces, imports). */
    private static function stripAtRules(string $css): string
    {
        $out = '';
        $i   = 0;
        $n   = strlen($css);
        while ($i < $n) {
            $at = strpos($css, needle: '@', offset: $i);
            if (false === $at) {
                $out .= substr($css, $i);
                break;
            }
            $out .= substr($css, $i, $at - $i);
            $i   = self::atRuleEnd($css, $at);
        }

        return str_contains($out, '{') ? $out : '';
    }

    public function isEmpty(): bool
    {
        return [] === $this->rules;
    }

    /**
     * The declarations that apply to an element, lowest specificity first so later entries win.
     *
     * @return array<string, string>
     */
    public function match(Element $element): array
    {
        $classes = preg_split('/\s+/', trim($element->attribute('class') ?? ''), flags: PREG_SPLIT_NO_EMPTY);
        $have    = [];
        foreach (false === $classes ? [] : $classes as $class) {
            $have[$class] = true;
        }
        $id      = $element->id();
        $name    = strtolower($element->name);
        $matched = [];
        foreach ($this->rules as [$tag, $wantClasses, $wantId, $specificity, $order, $declarations]) {
            if (! self::matches($tag, $wantClasses, $wantId, $name, $have, $id)) {
                continue;
            }
            $matched[] = [$specificity, $order, $declarations];
        }
        usort(
            $matched,
            /**
             * @param array{0: int, 1: int, 2: array<string, string>} $a
             * @param array{0: int, 1: int, 2: array<string, string>} $b
             */
            static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]],
        );
        $out = [];
        foreach ($matched as [, , $declarations]) {
            foreach ($declarations as $property => $value) {
                $out[$property] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, string> $declarations */
    private function addRule(string $selector, array $declarations): void
    {
        $parsed = self::simpleSelector($selector);
        if (null === $parsed || [] === $declarations) {
            return;
        }
        [$tag, $classes, $id] = $parsed;
        $specificity   = (null === $id ? 0 : 100) + (10 * count($classes)) + ('*' === $tag ? 0 : 1);
        $this->rules[] = [$tag, $classes, $id, $specificity, count($this->rules), $declarations];
    }
}
