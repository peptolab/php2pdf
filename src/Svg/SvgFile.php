<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Core\Limits;
use Php2Pdf\Core\Silencer;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Geometry\Dimensions;

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
        public readonly Limits $limits,
    ) {}

    /** @throws PdfException when the data is not SVG, or SVGZ that decompresses past the limit */
    public static function fromData(string $data, ?string $baseDirectory = null, ?Limits $limits = null): self
    {
        $limits ??= Limits::default();
        if (strlen($data) > 2 && "\x1F\x8B" === substr($data, offset: 0, length: 2)) {
            $data = self::gunzip($data, $limits);
        }
        if (! str_contains($data, '<svg') && ! str_contains($data, '<SVG')) {
            throw new ParseException('Not an SVG document.');
        }

        return new self($data, null, $baseDirectory, $limits);
    }

    public static function open(string $path, ?Limits $limits = null): self
    {
        $data = is_file($path) ? file_get_contents($path) : false;
        if (false === $data) {
            throw new IoException(sprintf('Cannot read SVG file "%s".', $path));
        }
        $file = self::fromData($data, dirname($path), $limits);

        return new self($file->data, $path, $file->baseDirectory, $file->limits);
    }

    private static function gunzip(string $data, Limits $limits): string
    {
        $decoded = Silencer::run(static fn(): string|false => zlib_decode($data, $limits->maxDecodedBytes));
        if (false === $decoded) {
            throw new ParseException(sprintf(
                'Cannot decompress the SVGZ data, or it decompresses to more than %d bytes.',
                $limits->maxDecodedBytes,
            ));
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
            $root = XmlParser::parse($this->data, $this->limits->maxNesting);
            if ('svg' !== $root->name) {
                throw new ParseException(sprintf('Expected an <svg> root element, found <%s>.', $root->name));
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
