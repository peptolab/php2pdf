<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

/**
 * Which page box bounds an imported page.
 *
 * @api
 */
enum PageBox: string
{
    case Media = 'MediaBox';
    case Crop  = 'CropBox';
    case Bleed = 'BleedBox';
    case Trim  = 'TrimBox';
    case Art   = 'ArtBox';
}
