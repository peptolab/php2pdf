<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Override;
use Php2Pdf\Core\Limits;

/**
 * Baseline TIFF, page by page: see TiffPage for the layouts read natively.
 *
 * @internal
 */
final readonly class TiffDecoder implements ImageDecoderInterface
{
    #[Override]
    public function accepts(string $data): bool
    {
        return TiffFile::isTiff($data);
    }

    #[Override]
    public function decode(string $data, Limits $limits, ImageOptions $options): ImageData|ImageTraits
    {
        $page = new TiffPage(new TiffFile($data), $options->page - 1, $limits);

        return $page->read() ?? $page->traits();
    }

    #[Override]
    public function hasPages(): bool
    {
        return true;
    }

    #[Override]
    public function name(): string
    {
        return 'TIFF';
    }
}
