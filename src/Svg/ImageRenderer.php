<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Exception\PdfException;
use PhpPdf\Fit\Fit;
use PhpPdf\Fit\FitMethod;
use PhpPdf\Fit\Position;
use PhpPdf\Geometry\Box;

use function base64_decode;
use function count;
use function file_get_contents;
use function is_file;
use function preg_match;
use function preg_replace;
use function preg_split;
use function rawurldecode;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

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
        $position = Position::percent($horizontal, $vertical);

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

    /** The bytes of an image reference: a data URI, or a file relative to the SVG. */
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
        $base = $this->context->file->baseDirectory;
        $path = str_starts_with($href, '/') || null === $base ? $href : "{$base}/{$href}";
        if (! is_file($path)) {
            $this->context->warn('missing image files');

            return null;
        }
        $data = file_get_contents($path);

        return false === $data ? null : $data;
    }
}
