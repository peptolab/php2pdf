<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Core\Silencer;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function getimagesizefromstring;
use function sprintf;

/**
 * The decoders images are read with, tried in order by their first bytes. An image no decoder
 * reads, or whose layout its decoder does not read, is converted to PNG (through ext-imagick or
 * ext-gd); a conversion that would lose data is refused unless the options accept it.
 *
 * A document reads its images with DocumentOptions::$imageDecoders; add a decoder of your own
 * with with():
 *
 *     new DocumentOptions(imageDecoders: ImageDecoders::default()->with(new WebpDecoder()))
 *
 * @api
 */
final class ImageDecoders
{
    /** The default decoders, shared: an instance never changes once built. */
    private static ?self $default = null;

    /**
     * Whether JPEG, JPEG 2000, TIFF, PNG and JBIG2 are read after $decoders. The built-in
     * decoders are made only for an image whose first bytes are theirs, so a document without
     * images never loads them.
     */
    private bool $builtIn = false;

    /** @param list<ImageDecoderInterface> $decoders */
    public function __construct(
        private readonly array $decoders,
        private readonly ?PngDecoder $png = null,
    ) {}

    /** JPEG, JPEG 2000, TIFF, PNG and JBIG2. */
    public static function default(): self
    {
        if (null === self::$default) {
            self::$default          = new self([]);
            self::$default->builtIn = true;
        }

        return self::$default;
    }

    /**
     * @param string|null $name the file the data came from, for error messages
     *
     * @throws PdfException for unreadable data, a missing page, an image over the limits, or a
     *                      conversion that would lose data when that is not accepted
     */
    public function read(string $data, Limits $limits, ImageOptions $options, ?string $name = null): ImageData
    {
        $traits  = null;
        $decoder = $this->decoderFor($data);
        if (null !== $decoder) {
            if ($options->page > 1 && ! $decoder->hasPages()) {
                throw new ParseException(sprintf(
                    '%s files have one page; page %d was asked for.',
                    $decoder->name(),
                    $options->page,
                ));
            }
            $image = $decoder->decode($data, $limits, $options);
            if ($image instanceof ImageData) {
                return $image;
            }
            $traits = $image;
        }

        return $this->converted($data, $limits, $options, $traits, $name);
    }

    /**
     * These decoders with another tried first: for a format they do not read, or in place of
     * the one that reads it.
     */
    public function with(ImageDecoderInterface $decoder): self
    {
        $with          = new self([$decoder, ...$this->decoders], $this->png);
        $with->builtIn = $this->builtIn;

        return $with;
    }

    /**
     * The image converted to PNG and read from that.
     *
     * @throws PdfException
     */
    private function converted(
        string $data,
        Limits $limits,
        ImageOptions $options,
        ?ImageTraits $traits,
        ?string $name,
    ): ImageData {
        $size = Silencer::run(
            /** @return array{0: int, 1: int, 2: int, 3: string, bits: int, channels: int, mime: string}|false */
            static fn(): array|false => getimagesizefromstring($data),
        );
        if (false !== $size) {
            $limits->assertImagePixels($size[0], $size[1]);
        }
        $png = Transcoder::toPng(
            $data,
            $options->page,
            $traits ?? ImageTraits::sniff($data, $size),
            match (true) {
                $options->allowLossyConversion => null,
                null === $name => 'The image',
                default => sprintf('Image file "%s"', $name),
            },
        );
        return ($this->png ?? new PngDecoder())->decode($png, $limits, new ImageOptions());
    }

    /** The first decoder that accepts the data, or null when none does. */
    private function decoderFor(string $data): ?ImageDecoderInterface
    {
        foreach ($this->decoders as $decoder) {
            if ($decoder->accepts($data)) {
                return $decoder;
            }
        }
        if (! $this->builtIn) {
            return null;
        }
        // The first byte tells the built-in formats apart; the decoder itself then checks the
        // whole signature.
        $decoder = match ($data[0] ?? '') {
            "\xFF"   => "\xD8" === ($data[1] ?? '') ? new JpegDecoder() : new JpxDecoder(),
            "\x00"   => new JpxDecoder(),
            'I', 'M' => new TiffDecoder(),
            "\x89"   => $this->png ?? new PngDecoder(),
            "\x97"   => new Jbig2Decoder(),
            default  => null,
        };

        return null !== $decoder && $decoder->accepts($data) ? $decoder : null;
    }
}
