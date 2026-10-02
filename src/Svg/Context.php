<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Document\Document;

use function array_key_exists;
use function array_keys;
use function sqrt;

/**
 * What a conversion shares between its parts: the document and file, the style sheet, font and
 * paint-server resolvers, the guard that references are expanded through, the current viewport
 * size for percentages, and warnings about features that were skipped.
 *
 * @internal
 */
final class Context
{
    public readonly StyleSheet $sheet;
    public readonly PaintServers $servers;
    public readonly ReferenceGuard $guard;
    public float $viewportWidth  = 0.0;
    public float $viewportHeight = 0.0;

    /** @var array<string, true> */
    private array $warnings = [];

    public function __construct(
        public readonly Document $document,
        public readonly SvgFile $file,
        public readonly FontResolver $fonts,
    ) {
        $this->sheet   = $file->styleSheet();
        $this->guard   = ReferenceGuard::of($file->limits);
        $this->servers = new PaintServers($file);
    }

    /** A length attribute in user units; percentages resolve against the viewport axis. */
    public function length(?string $value, string $axis, Style $style, ?float $default = 0.0): ?float
    {
        $reference = match ($axis) {
            'x'     => $this->viewportWidth,
            'y'     => $this->viewportHeight,
            default => $this->viewportDiagonal(),
        };

        return Length::parse($value, $reference, $style->fontSize()) ?? $default;
    }

    /** The viewport's diagonal over the square root of two, which non-axial percentages resolve against. */
    public function viewportDiagonal(): float
    {
        return sqrt(($this->viewportWidth ** 2 + $this->viewportHeight ** 2) / 2.0);
    }

    public function warn(string $feature): void
    {
        if (array_key_exists($feature, $this->warnings)) {
            return;
        }
        $this->warnings[$feature] = true;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return array_keys($this->warnings);
    }
}
