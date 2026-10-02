<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\PlaceableInterface;

/**
 * Turns an SVG into artwork that can be placed through the fit engine.
 *
 * @api
 */
interface SvgConverterInterface
{
    public function convert(SvgFile $svg, SvgDocumentInterface $document): PlaceableInterface;
}
