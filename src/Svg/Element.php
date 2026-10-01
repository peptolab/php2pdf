<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use function array_key_exists;
use function is_string;
use function trim;

/**
 * An element of a parsed SVG document: its local name, attributes and children in document
 * order. Text content is kept as plain strings between child elements.
 *
 * @api
 */
final class Element
{
    /**
     * @param array<string, string> $attributes
     * @param list<Element|string>  $children
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes = [],
        public array $children = [],
    ) {}

    public function attribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    /** The first child element with the given name, or null. */
    public function child(string $name): ?self
    {
        foreach ($this->elements() as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<self> child elements only */
    public function elements(): array
    {
        $out = [];
        foreach ($this->children as $child) {
            if (! $child instanceof self) {
                continue;
            }
            $out[] = $child;
        }

        return $out;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /** Whether the element has any non-blank text or any element children. */
    public function hasContent(): bool
    {
        foreach ($this->children as $child) {
            if ($child instanceof self || '' !== trim($child)) {
                return true;
            }
        }

        return false;
    }

    /** The "href" or "xlink:href" attribute. */
    public function href(): ?string
    {
        return $this->attributes['href'] ?? $this->attributes['xlink:href'] ?? null;
    }

    public function id(): ?string
    {
        return $this->attributes['id'] ?? null;
    }

    /** All text content, including that of descendants. */
    public function text(): string
    {
        $text = '';
        foreach ($this->children as $child) {
            $text .= is_string($child) ? $child : $child->text();
        }

        return $text;
    }
}
