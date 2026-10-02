<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Closure;
use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function array_pop;
use function implode;
use function sprintf;

/**
 * Keeps one conversion's drawing finite. Every reference an SVG expands by id (a <use>, clip
 * path, mask, pattern or marker) enters here while it is expanded, and every element drawn
 * descends here: a reference back to anything still being expanded, of whatever kind, is
 * circular; nesting past maxSvgDepth, or expanding more than maxSvgWork references in all, is
 * refused before it can exhaust the stack or time. Clip paths cost no work: their children are
 * shapes, and any clip path of their own is a single one, so they cannot multiply.
 *
 * @internal
 */
final class ReferenceGuard
{
    /** Kinds of reference that cannot fan out, so cost no work. */
    private const array FREE = ['clip-path' => true];

    /** @var array<string, true> the ids being expanded, each prefixed with "#" */
    private array $active = [];

    /** @var list<array{0: string, 1: string}> the kind and id of each reference being expanded, in order */
    private array $stack = [];

    private int $depth = 0;
    private int $work  = 0;

    public function __construct(
        private readonly int $maxDepth,
        private readonly int $maxWork,
    ) {}

    public static function of(Limits $limits): self
    {
        return new self($limits->maxSvgDepth, $limits->maxSvgWork);
    }

    /** Steps back out of an element. */
    public function ascend(): void
    {
        --$this->depth;
    }

    /**
     * Steps into an element.
     *
     * @throws ParseException when drawing nests deeper than the limit
     */
    public function descend(): void
    {
        if (++$this->depth > $this->maxDepth) {
            throw new ParseException(sprintf(
                'The SVG nests elements and references more than %d deep.',
                $this->maxDepth,
            ));
        }
    }

    /**
     * Starts expanding a reference.
     *
     * @param string $kind what refers to the id, as named in messages: "use", "clip-path", "mask"...
     *
     * @throws ParseException for a circular reference, or one over the depth or work limit
     */
    public function enter(string $kind, string $id): void
    {
        if (array_key_exists("#{$id}", $this->active)) {
            throw new ParseException(sprintf(
                'The SVG refers to "%s" from within itself: %s.',
                $id,
                $this->path($kind, $id),
            ));
        }
        if (! array_key_exists($kind, self::FREE) && ++$this->work > $this->maxWork) {
            throw new ParseException(sprintf('The SVG expands more than %d references.', $this->maxWork));
        }
        $this->descend();
        $this->active["#{$id}"] = true;
        $this->stack[]          = [$kind, $id];
    }

    /** Finishes expanding the reference entered last. */
    public function leave(): void
    {
        $last = array_pop($this->stack);
        if (null === $last) {
            return;
        }
        unset($this->active["#{$last[1]}"]);
        $this->ascend();
    }

    /**
     * Runs $body while a reference is expanded, leaving it again however $body ends.
     *
     * @template T
     *
     * @param Closure(): T $body
     *
     * @return T
     *
     * @throws ParseException for a circular reference, or one over the depth or work limit
     */
    public function within(string $kind, string $id, Closure $body): mixed
    {
        $this->enter($kind, $id);

        try {
            return $body();
        } finally {
            $this->leave();
        }
    }

    /** The references from the earlier one to $id up to this one, which closes the loop. */
    private function path(string $kind, string $id): string
    {
        $steps = [];
        foreach ($this->stack as [$activeKind, $activeId]) {
            if ($activeId === $id) {
                $steps = [];
            }
            $steps[] = "{$activeKind} #{$activeId}";
        }
        $steps[] = "{$kind} #{$id}";

        return implode(' -> ', $steps);
    }
}
