<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;

use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function round;

/**
 * A page of an existing PDF: its boxes, rotation, resources and content, for reading and for
 * building a reusable template from.
 *
 * @api
 */
final readonly class PdfPage
{
    /** @internal */
    public function __construct(
        private PdfReader $reader,
        private Dictionary $dictionary,
        public int $number,
    ) {}

    /** @throws ParseException */
    public function artBox(): Box
    {
        return $this->clamped('ArtBox', $this->cropBox());
    }

    /** @throws ParseException */
    public function bleedBox(): Box
    {
        return $this->clamped('BleedBox', $this->cropBox());
    }

    /** @throws ParseException */
    public function box(PageBox $box): Box
    {
        return match ($box) {
            PageBox::Media => $this->mediaBox(),
            PageBox::Crop  => $this->cropBox(),
            PageBox::Bleed => $this->bleedBox(),
            PageBox::Trim  => $this->trimBox(),
            PageBox::Art   => $this->artBox(),
        };
    }

    /**
     * The decoded content stream(s), concatenated.
     *
     * @internal
     *
     * @throws ParseException
     */
    public function content(): string
    {
        $contents = $this->reader->get($this->dictionary, 'Contents');
        $streams  = is_array($contents) ? $contents : [];
        if ($contents instanceof Stream) {
            $streams = [$contents];
        }
        $parts = [];
        foreach ($streams as $item) {
            $stream = $this->reader->resolve($item);
            if ($stream instanceof Stream) {
                $parts[] = $this->reader->decode($stream);
            }
        }

        return implode("\n", $parts);
    }

    /** @throws ParseException */
    public function cropBox(): Box
    {
        return $this->clamped('CropBox', $this->mediaBox());
    }

    /**
     * The device colour spaces the page draws with, from its resources and content.
     *
     * @internal
     *
     * @return list<string> DeviceGray, DeviceRGB and DeviceCMYK, as found
     *
     * @throws ParseException
     */
    public function deviceColourSpaces(): array
    {
        return (new ColourScanner($this->reader))->scan($this);
    }

    /** @internal */
    public function dictionary(): Dictionary
    {
        return $this->dictionary;
    }

    /**
     * @internal
     *
     * @throws ParseException
     */
    public function group(): ?Dictionary
    {
        return $this->reader->dict($this->dictionary, 'Group');
    }

    /** @throws ParseException */
    public function mediaBox(): Box
    {
        return $this->rect('MediaBox') ?? new Box(0.0, 0.0, 612.0, 792.0);
    }

    /**
     * @internal
     *
     * @throws ParseException
     */
    public function resources(): ?Dictionary
    {
        return $this->reader->dict($this->dictionary, 'Resources');
    }

    /**
     * Display rotation: 0, 90, 180 or 270.
     *
     * @throws ParseException
     */
    public function rotation(): int
    {
        $rotate = $this->reader->get($this->dictionary, 'Rotate');
        $value  = is_int($rotate) || is_float($rotate) ? (int) round($rotate / 90.0) * 90 : 0;

        return (($value % 360) + 360) % 360;
    }

    /** @throws ParseException */
    public function trimBox(): Box
    {
        return $this->clamped('TrimBox', $this->cropBox());
    }

    /**
     * The base font names of the fonts the page uses without embedding their programs.
     *
     * @return list<string>
     *
     * @throws ParseException
     */
    public function unembeddedFonts(): array
    {
        return (new FontScanner($this->reader))->scan($this);
    }

    /** @throws ParseException */
    private function clamped(string $key, Box $fallback): Box
    {
        $box = $this->rect($key);
        if (null === $box) {
            return $fallback;
        }
        $inside = $box->intersect($this->mediaBox());

        return $inside->isEmpty() ? $fallback : $inside;
    }

    /** @throws ParseException */
    private function rect(string $key): ?Box
    {
        $value = $this->reader->get($this->dictionary, $key);
        if (! is_array($value) || count($value) < 4) {
            return null;
        }
        $n = [];
        foreach (array_slice(array_values($value), offset: 0, length: 4) as $v) {
            $v = $this->reader->resolve($v);
            if (! is_int($v) && ! is_float($v)) {
                return null;
            }
            $n[] = (float) $v;
        }
        $box = Box::fromCorners($n[0] ?? 0.0, $n[1] ?? 0.0, $n[2] ?? 0.0, $n[3] ?? 0.0);

        return $box->isEmpty() ? null : $box;
    }
}
