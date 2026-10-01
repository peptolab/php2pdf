<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Exception\InvalidArgumentException;

/**
 * Where a link or bookmark goes: a page shown whole, a page scrolled to a vertical position, a
 * point on a page at a zoom level, or a named destination defined with Document::addDestination().
 * Coordinates are in PDF user space of the target page (origin bottom-left, points).
 *
 * @api
 */
final readonly class Destination
{
    private function __construct(
        public ?int $page,
        public ?string $name,
        public string $kind,
        public ?float $left,
        public ?float $top,
        public ?float $zoom,
    ) {
        if (null !== $page && $page < 1) {
            throw new InvalidArgumentException('Page numbers start at 1.');
        }
    }

    /** A point on a page, optionally at a zoom factor (null keeps the viewer's current zoom). */
    public static function at(int $page, float $left, float $top, ?float $zoom = null): self
    {
        return new self($page, null, 'XYZ', $left, $top, $zoom);
    }

    /** A named destination registered on the document. */
    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new InvalidArgumentException('A destination name cannot be empty.');
        }

        return new self(null, $name, 'Named', null, null, null);
    }

    /** A whole page, fitted to the window. */
    public static function page(int $page): self
    {
        return new self($page, null, 'Fit', null, null, null);
    }

    /** A page scrolled so that $top is at the top of the window, fitted to the window's width. */
    public static function top(int $page, float $top): self
    {
        return new self($page, null, 'FitH', null, $top, null);
    }

    /**
     * The explicit destination array for a page reference, or the name for a named destination.
     *
     * @internal
     *
     * @return list<mixed>|string
     */
    public function toPdf(?Reference $pageRef): array|string
    {
        if (null !== $this->name) {
            return $this->name;
        }
        if (null === $pageRef) {
            throw new InvalidArgumentException('The destination page does not exist.');
        }

        return match ($this->kind) {
            'XYZ'   => [$pageRef, new Name('XYZ'), $this->left, $this->top, $this->zoom],
            'FitH'  => [$pageRef, new Name('FitH'), $this->top],
            default => [$pageRef, new Name('Fit')],
        };
    }
}
