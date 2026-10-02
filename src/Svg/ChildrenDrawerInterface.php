<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;

/**
 * Draws an element's children: what patterns, markers and masks use to render their content
 * with the renderer that is drawing them.
 *
 * @internal
 */
interface ChildrenDrawerInterface
{
    /**
     * @throws IoException
     * @throws ParseException
     */
    public function drawChildren(Canvas $canvas, Element $element, Style $style): void;
}
