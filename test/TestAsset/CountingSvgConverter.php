<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use ArrayObject;
use Override;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Svg\SvgConverterInterface;
use Php2Pdf\Svg\SvgDocumentInterface;
use Php2Pdf\Svg\SvgFile;

/** A converter that counts its conversions in a shared tally and draws an empty template. */
final readonly class CountingSvgConverter implements SvgConverterInterface
{
    /** @param ArrayObject<int, string> $tally the paths converted, one entry per conversion */
    public function __construct(
        private ArrayObject $tally,
    ) {}

    #[Override]
    public function convert(SvgFile $svg, SvgDocumentInterface $document): PlaceableInterface
    {
        $this->tally->append($svg->path ?? '(no path)');

        return $document->template(new Dimensions(10.0, 10.0));
    }
}
