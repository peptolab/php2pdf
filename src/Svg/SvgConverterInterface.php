<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Document\Document;

/**
 * Turns an SVG into artwork that can be placed through the fit engine.
 *
 * @api
 */
interface SvgConverterInterface
{
    public function convert(SvgFile $svg, Document $document): PlaceableInterface;
}
