<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use Closure;
use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\FillRule;
use PhpPdf\Canvas\SoftMaskType;
use PhpPdf\Document\Dimensions;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;
use PhpPdf\Geometry\Origin;

use function trim;

/**
 * Applies <mask> elements as soft masks: the mask's children, drawn in the masked element's user
 * space (or its bounding box with maskContentUnits="objectBoundingBox") and clipped to the mask's
 * region (by default 10% beyond the bounding box on every side), mask what the element draws by
 * their luminance or, with mask-type="alpha", by their alpha.
 *
 * @internal
 */
final readonly class MaskBuilder
{
    private const float HUGE = 100_000.0;

    /** @param Closure(Canvas, Element, Style): void $children draws an element's children */
    public function __construct(
        private Context $context,
        private ClipBuilder $clips,
        private Closure $children,
    ) {}

    public function apply(Canvas $canvas, string $id, Element $element, Style $style): void
    {
        $mask = $this->context->file->byId($id);
        if (null === $mask || 'mask' !== $mask->name) {
            return;
        }
        $bounds   = $this->clips->bounds($element, $style) ?? new Box(0.0, 0.0, 0.0, 0.0);
        $region   = $this->region($mask, $bounds);
        $template = $this->context->document->template(
            new Dimensions(1.0, 1.0),
            Origin::BottomLeft,
            new Box(-self::HUGE, -self::HUGE, 2.0 * self::HUGE, 2.0 * self::HUGE),
        );
        $content = $template->canvas();
        Painter::clipTo(
            $content,
            PathData::rect($region->x, $region->y, $region->width, $region->height),
            FillRule::NonZero,
        );
        if ('objectBoundingBox' === trim($mask->attribute('maskContentUnits') ?? '')) {
            $content->concat(new Matrix($bounds->width, 0.0, 0.0, $bounds->height, $bounds->x, $bounds->y));
        }
        $maskStyle = Style::root()->derive($mask, $this->context->sheet);
        ($this->children)($content, $mask, $maskStyle);
        $canvas->setSoftMask($template, $maskStyle->maskAlpha() ? SoftMaskType::Alpha : SoftMaskType::Luminosity);
    }

    /** The mask's region, in user space or as fractions of the bounding box (the default). */
    private function region(Element $mask, Box $bounds): Box
    {
        $x      = $mask->attribute('x') ?? '-10%';
        $y      = $mask->attribute('y') ?? '-10%';
        $width  = $mask->attribute('width') ?? '120%';
        $height = $mask->attribute('height') ?? '120%';
        if ('userSpaceOnUse' === trim($mask->attribute('maskUnits') ?? '')) {
            $viewWidth  = $this->context->viewportWidth;
            $viewHeight = $this->context->viewportHeight;

            return new Box(
                Length::parse($x, $viewWidth) ?? 0.0,
                Length::parse($y, $viewHeight) ?? 0.0,
                Length::parse($width, $viewWidth) ?? 0.0,
                Length::parse($height, $viewHeight) ?? 0.0,
            );
        }

        return new Box(
            $bounds->x + ((Length::parse($x, 1.0) ?? 0.0) * $bounds->width),
            $bounds->y + ((Length::parse($y, 1.0) ?? 0.0) * $bounds->height),
            (Length::parse($width, 1.0) ?? 0.0) * $bounds->width,
            (Length::parse($height, 1.0) ?? 0.0) * $bounds->height,
        );
    }
}
