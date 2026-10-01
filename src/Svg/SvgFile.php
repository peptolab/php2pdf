<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Document\Dimensions;
use PhpPdf\Exception\IoException;
use PhpPdf\Exception\PdfException;

use function array_key_exists;
use function dirname;
use function file_get_contents;
use function is_file;
use function sprintf;
use function str_contains;
use function strlen;
use function substr;
use function zlib_decode;

/**
 * An SVG document (plain or gzip-compressed .svgz) ready for conversion. Pass it to
 * Document::svg() to place it as artwork.
 *
 * @api
 */
final class SvgFile
{
    private ?Element $root = null;

    /** @var array<string, Element>|null */
    private ?array $ids = null;

    private ?StyleSheet $styleSheet = null;

    private function __construct(
        public readonly string $data,
        public readonly ?string $path,
        public readonly ?string $baseDirectory,
    ) {}

    public static function fromData(string $data, ?string $baseDirectory = null): self
    {
        if (strlen($data) > 2 && "\x1F\x8B" === substr($data, offset: 0, length: 2)) {
            $data = self::gunzip($data);
        }
        if (! str_contains($data, '<svg') && ! str_contains($data, '<SVG')) {
            throw new PdfException('Not an SVG document.');
        }

        return new self($data, null, $baseDirectory);
    }

    public static function open(string $path): self
    {
        $data = is_file($path) ? file_get_contents($path) : false;
        if (false === $data) {
            throw new IoException(sprintf('Cannot read SVG file "%s".', $path));
        }
        $file = self::fromData($data, dirname($path));

        return new self($file->data, $path, $file->baseDirectory);
    }

    private static function gunzip(string $data): string
    {
        $decoded = zlib_decode($data);
        if (false === $decoded) {
            throw new PdfException('Cannot decompress the SVGZ data.');
        }

        return $decoded;
    }

    /** The element with the given id, or null. */
    public function byId(string $id): ?Element
    {
        if (null === $this->ids) {
            $this->ids = [];
            $this->index($this->root());
        }

        return $this->ids[$id] ?? null;
    }

    public function root(): Element
    {
        if (null === $this->root) {
            $root = XmlParser::parse($this->data);
            if ('svg' !== $root->name) {
                throw new PdfException(sprintf('Expected an <svg> root element, found <%s>.', $root->name));
            }
            $this->root = $root;
        }

        return $this->root;
    }

    /** The intrinsic size in points, from width/height, else the viewBox, else 300 by 150 px. */
    public function size(): Dimensions
    {
        [$width, $height] = Viewport::intrinsicSize($this->root());

        return new Dimensions($width * Length::PT_PER_PX, $height * Length::PT_PER_PX);
    }

    /** The combined rules of every <style> element. */
    public function styleSheet(): StyleSheet
    {
        if (null === $this->styleSheet) {
            $css = '';
            $this->collectCss($this->root(), $css);
            $this->styleSheet = StyleSheet::fromCss($css);
        }

        return $this->styleSheet;
    }

    private function collectCss(Element $element, string &$css): void
    {
        foreach ($element->elements() as $child) {
            if ('style' === $child->name) {
                $css .= "{$child->text()}\n";
                continue;
            }
            $this->collectCss($child, $css);
        }
    }

    private function index(Element $element): void
    {
        $id = $element->id();
        if (null !== $id && null === $this->ids) {
            $this->ids = [];
        }
        if (null !== $id && ! array_key_exists($id, $this->ids ?? [])) {
            $this->ids[$id] = $element;
        }
        foreach ($element->elements() as $child) {
            $this->index($child);
        }
    }
}
