<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Fit\Position;
use Php2Pdf\Geometry\Box;

use function base64_decode;
use function count;
use function file_get_contents;
use function is_file;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rawurldecode;
use function realpath;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Places <image> elements: PNG and JPEG data URIs or files relative to the SVG, fitted per
 * preserveAspectRatio.
 *
 * @internal
 */
final class ImageRenderer
{
    public function __construct(
        private readonly Context $context,
    ) {}

    private static function fit(?string $preserve): Fit
    {
        $words = preg_split('/\s+/', strtolower(trim($preserve ?? 'xmidymid meet')), flags: PREG_SPLIT_NO_EMPTY);
        $words = false === $words ? [] : $words;
        $align = $words[0] ?? 'xmidymid';
        if ('none' === $align) {
            return Fit::entire();
        }
        $horizontal = match (substr($align, offset: 0, length: 4)) {
            'xmin'  => 0.0,
            'xmax'  => 1.0,
            default => 0.5,
        };
        $vertical = match (substr($align, offset: 4, length: 4)) {
            'ymin'  => 1.0,
            'ymax'  => 0.0,
            default => 0.5,
        };
        $position = new Position($horizontal, $vertical);

        return 'slice' === ($words[count($words) - 1] ?? '') ? Fit::slice($position) : Fit::meet($position);
    }

    public function render(Canvas $canvas, Element $element, Style $style): void
    {
        if (! $style->isVisible()) {
            return;
        }
        $data = $this->data($element->href());
        if (null === $data) {
            return;
        }
        try {
            $image = $this->context->document->imageFromData($data);
        } catch (PdfException) {
            $this->context->warn('images in unsupported formats');

            return;
        }
        $natural = $image->naturalSize(96.0);
        $x       = $this->context->length($element->attribute('x'), 'x', $style) ?? 0.0;
        $y       = $this->context->length($element->attribute('y'), 'y', $style) ?? 0.0;
        $width   = $this->context->length($element->attribute('width'), 'x', $style, null) ?? $natural->width;
        $height  = $this->context->length($element->attribute('height'), 'y', $style, null) ?? $natural->height;
        if ($width <= 0.0 || $height <= 0.0) {
            return;
        }
        $canvas->save();
        // Place in a local y-up space so the fit engine keeps the image upright.
        $canvas->translate($x, $y + $height)->scale(1.0, -1.0);
        $fit = self::fit($element->attribute('preserveAspectRatio'));
        if (FitMethod::Slice === $fit->method) {
            Painter::clipTo($canvas, PathData::rect(0.0, 0.0, $width, $height), $style->clipRule());
        }
        $canvas->place($image, new Box(0.0, 0.0, $width, $height), $fit);
        $canvas->restore();
    }

    /** The bytes of an image reference: a data URI, or a file inside the SVG's directory. */
    private function data(?string $href): ?string
    {
        if (null === $href) {
            return null;
        }
        $href = trim($href);
        $m    = [];
        if (preg_match('/^data:([^;,]*)((?:;[^;,]*)*),(.*)$/s', $href, $m) === 1) {
            $encoded = $m[3] ?? '';
            $data    = str_contains($m[2] ?? '', ';base64')
                ? base64_decode(preg_replace('/\s+/', replacement: '', subject: $encoded) ?? '', strict: true)
                : rawurldecode($encoded);

            return false === $data ? null : $data;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            $this->context->warn('remote images');

            return null;
        }
        $path = $this->localPath($href);
        if (null === $path) {
            return null;
        }
        $data = file_get_contents($path);

        return false === $data ? null : $data;
    }

    /**
     * The real path of an image file the SVG refers to, which must lie inside the SVG's own
     * directory: absolute paths, references that climb out of it (directly or through a link)
     * and any file reference from SVG with no directory are refused.
     */
    private function localPath(string $href): ?string
    {
        $base = $this->context->file->baseDirectory;
        $root = null === $base ? false : realpath($base);
        if (false === $root || str_starts_with($href, '/') || str_starts_with($href, '\\')) {
            $this->context->warn('image files outside the SVG\'s directory');

            return null;
        }
        $path = realpath("{$root}/" . rawurldecode($href));
        if (false === $path || ! is_file($path)) {
            $this->context->warn('missing image files');

            return null;
        }
        if (! str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            $this->context->warn('image files outside the SVG\'s directory');

            return null;
        }

        return $path;
    }
}
