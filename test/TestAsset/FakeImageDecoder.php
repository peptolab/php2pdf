<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use Override;
use Php2Pdf\Core\Limits;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageDecoderInterface;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\ImageTraits;

use function str_starts_with;

/** A decoder for data starting "FAKE": it gives back what it was made with. */
final readonly class FakeImageDecoder implements ImageDecoderInterface
{
    public function __construct(
        private ImageData|ImageTraits $result,
        private bool $pages = false,
    ) {}

    #[Override]
    public function accepts(string $data): bool
    {
        return str_starts_with($data, 'FAKE');
    }

    #[Override]
    public function decode(string $data, Limits $limits, ImageOptions $options): ImageData|ImageTraits
    {
        return $this->result;
    }

    #[Override]
    public function hasPages(): bool
    {
        return $this->pages;
    }

    #[Override]
    public function name(): string
    {
        return 'Fake';
    }
}
