<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

use Php2Pdf\Exception\PdfException;

use function array_key_exists;
use function array_keys;
use function sprintf;

/**
 * Named styles with inheritance: a style defined "based on" another takes that style's values
 * for everything it leaves unset.
 *
 * @api
 */
final class StyleRegistry
{
    /** @var array<string, TextStyle> resolved styles */
    private array $styles = [];

    public function define(string $name, TextStyle $style, ?string $basedOn = null): self
    {
        $base = null === $basedOn ? new TextStyle() : $this->get($basedOn);

        $this->styles[$name] = $base->merge($style);

        return $this;
    }

    public function get(string $name): TextStyle
    {
        return $this->styles[$name] ?? throw new PdfException(sprintf('Text style "%s" is not defined.', $name));
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->styles);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->styles);
    }
}
