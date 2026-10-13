<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Core\Num;
use Php2Pdf\Exception\StateException;
use Php2Pdf\Geometry\Matrix;

use function array_pop;
use function count;
use function sprintf;

/**
 * A content stream being written, with the graphics state its operators leave: the current
 * transformation, saved and restored with q and Q, whether a path is open, and the text state and
 * fill colour last set, so they are written again only when they change.
 *
 * On a tagged page every operator is in a marked-content sequence: of the element being drawn
 * or, outside one, an artifact's. A sequence opens before the first operator drawn in it, and
 * closes when an element begins or ends, or the stream is finished.
 *
 * @internal
 */
final class ContentStream
{
    /** The text state and fill colour every page starts with. */
    private const array PAGE_STATE = [
        'Tf'   => null,
        'Tc'   => '0 Tc',
        'Tz'   => '100 Tz',
        'Ts'   => '0 Ts',
        'Tr'   => '0 Tr',
        'Tw'   => '0 Tw',
        'TL'   => '0 TL',
        'fill' => '0 g',
    ];

    /** A state not known: content drawn inside other content, or after raw operators. */
    private const array UNKNOWN_STATE = [
        'Tf'   => null,
        'Tc'   => null,
        'Tz'   => null,
        'Ts'   => null,
        'Tr'   => null,
        'Tw'   => null,
        'TL'   => null,
        'fill' => null,
    ];

    private string $ops = '';
    private Matrix $ctm;

    /** @var list<Matrix> */
    private array $ctmStack = [];

    /**
     * The text state and fill colour last written, as the operations that set them. Null is
     * unknown.
     *
     * @var array{Tf: string|null, Tc: string|null, Tz: string|null, Ts: string|null, Tr: string|null, Tw: string|null, TL: string|null, fill: string|null}
     */
    private array $state;

    /** @var list<array{Tf: string|null, Tc: string|null, Tz: string|null, Ts: string|null, Tr: string|null, Tw: string|null, TL: string|null, fill: string|null}> */
    private array $stateStack = [];

    private bool    $hasCurrentPoint = false;
    private bool    $finished        = false;
    private ?Tagger $tagger          = null;

    /** Whether a marked-content sequence is open. */
    private bool $marked = false;

    /**
     * @param bool $inheritsState whether the content is drawn inside other content, as a form
     *                            XObject is, so its state starts unknown
     */
    public function __construct(float $height, bool $topDown, bool $inheritsState)
    {
        $this->state = $inheritsState ? self::UNKNOWN_STATE : self::PAGE_STATE;
        $this->ctm   = Matrix::identity();
        if ($topDown) {
            $this->ctm = new Matrix(1.0, 0.0, 0.0, -1.0, 0.0, $height);
            $this->ops = '1 0 0 -1 0 ' . Num::format($height) . " cm\n";
        }
    }

    /** Appends operators and a newline. */
    public function append(string $operators): void
    {
        if (null !== $this->tagger && ! $this->marked) {
            $this->ops    .= "{$this->tagger->opening()}\n";
            $this->marked = true;
        }
        $this->ops .= "{$operators}\n";
    }

    /**
     * Appends a text object's opening operators, BT and what follows it. On a tagged page any
     * sequence open before it closes, and the sequence of what is drawn opens inside it.
     */
    public function beginTextObject(string $operators): void
    {
        if (null === $this->tagger) {
            $this->append("BT{$operators}");

            return;
        }
        $this->closeMarked();
        $this->ops .= "BT\n";
        $this->append(ltrim($operators));
    }

    /**
     * The operations, by the piece of state each sets, that change it, each recorded and preceded
     * by a space: as changes() for each, in one call for a text object's whole state.
     *
     * @param array<'Tf'|'Tc'|'Tz'|'Ts'|'Tr'|'Tw'|'TL'|'fill', string> $operations
     */
    public function changed(array $operations): string
    {
        $changed = '';
        foreach ($operations as $key => $operation) {
            if ($operation === ($this->state[$key] ?? null)) {
                continue;
            }
            $this->state[$key] = $operation;
            $changed           .= " {$operation}";
        }

        return $changed;
    }

    /**
     * Whether setting a piece of state to the operation changes it, recording it when it does.
     *
     * @param 'Tf'|'Tc'|'Tz'|'Ts'|'Tr'|'Tw'|'TL'|'fill' $key
     */
    public function changes(string $key, string $operation): bool
    {
        if ($operation === ($this->state[$key] ?? null)) {
            return false;
        }
        $this->state[$key] = $operation;

        return true;
    }

    /** Closes the open marked-content sequence, if there is one. */
    public function closeMarked(): void
    {
        if ($this->marked) {
            $this->ops    .= "EMC\n";
            $this->marked = false;
        }
    }

    public function closePath(): void
    {
        $this->append('h');
    }

    public function concat(Matrix $matrix): void
    {
        if ($matrix->isIdentity()) {
            return;
        }
        $this->ctm = $matrix->then($this->ctm);
        $this->append("{$matrix->toPdf()} cm");
    }

    public function ctm(): Matrix
    {
        return $this->ctm;
    }

    /**
     * The operation that last set a piece of state, or null when it is not known.
     *
     * @param 'Tf'|'Tc'|'Tz'|'Ts'|'Tr'|'Tw'|'TL'|'fill' $key
     */
    public function current(string $key): ?string
    {
        return $this->state[$key] ?? null;
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $this->path(Num::list([$x1, $y1, $x2, $y2, $x3, $y3]) . ' c');
    }

    /**
     * Ends a text object. On a tagged page the marked-content sequence open in it closes first,
     * as a sequence may not straddle the end of a text object.
     */
    public function endTextObject(): void
    {
        $this->closeMarked();
        $this->ops .= "ET\n";
    }

    /**
     * The content stream, sealed.
     *
     * @throws StateException when it was finished already, or a save() was not restored
     */
    public function finish(): string
    {
        if ($this->finished) {
            throw new StateException('Canvas has already been finished.');
        }
        if ([] !== $this->ctmStack) {
            throw new StateException(sprintf('Unbalanced save(): %d state(s) not restored.', count($this->ctmStack)));
        }
        $this->closeMarked();
        $this->finished = true;

        return $this->ops;
    }

    /**
     * Forgets a piece of state, so it is written next time.
     *
     * @param 'Tf'|'Tc'|'Tz'|'Ts'|'Tr'|'Tw'|'TL'|'fill' $key
     */
    public function forget(string $key): void
    {
        $this->state[$key] = null;
    }

    public function hasCurrentPoint(): bool
    {
        return $this->hasCurrentPoint;
    }

    public function lineTo(float $x, float $y): void
    {
        $this->path(Num::list([$x, $y]) . ' l');
    }

    public function moveTo(float $x, float $y): void
    {
        $this->path(Num::list([$x, $y]) . ' m');
    }

    /** Appends a painting or path-ending operator, which closes the open path. */
    public function paint(string $operator): void
    {
        $this->append($operator);
        $this->hasCurrentPoint = false;
    }

    /** Appends a path-construction operator, which leaves a path open. */
    public function path(string $operators): void
    {
        $this->append($operators);
        $this->hasCurrentPoint = true;
    }

    /** Appends operators the state cannot follow, so all of it becomes unknown. */
    public function raw(string $operators): void
    {
        $this->append($operators);
        $this->state = self::UNKNOWN_STATE;
    }

    public function rect(float $x, float $y, float $width, float $height): void
    {
        $this->path(Num::list([$x, $y, $width, $height]) . ' re');
    }

    /** @throws StateException without a matching save() */
    public function restore(): void
    {
        $ctm = array_pop($this->ctmStack) ?? throw new StateException(
            'restore() without a matching save().',
        );
        $this->ctm             = $ctm;
        $this->state           = array_pop($this->stateStack) ?? self::UNKNOWN_STATE;
        $this->hasCurrentPoint = false;
        $this->append('Q');
    }

    public function save(): void
    {
        $this->ctmStack[]   = $this->ctm;
        $this->stateStack[] = $this->state;
        $this->append('q');
    }

    /** Puts what is drawn from now on in the tagger's marked-content sequences. */
    public function tagWith(Tagger $tagger): void
    {
        $this->tagger = $tagger;
    }
}
