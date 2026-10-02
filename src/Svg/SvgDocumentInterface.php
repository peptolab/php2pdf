<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Font\FontRegistry;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Origin;
use Php2Pdf\Image\Image;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Import\HiddenLayers;
use Php2Pdf\Import\ImportedDocument;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Template\Template;

/**
 * What converting an SVG needs of the document it draws into: its fonts, and templates, images
 * and imported pages made in it. The Document is one.
 *
 * @api
 */
interface SvgDocumentInterface
{
    public function fonts(): FontRegistry;

    public function imageFromData(string $data, ?ImageOptions $options = null): Image;

    public function import(PdfFile|string $source, HiddenLayers $hiddenLayers = HiddenLayers::Keep): ImportedDocument;

    public function template(Dimensions $size, ?Origin $origin = null, ?Box $bbox = null): Template;
}
