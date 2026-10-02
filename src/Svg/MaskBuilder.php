<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Closure;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Canvas\SoftMaskType;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Origin;
use Php2Pdf\Geometry\Path;

/**
 * Applies <mask> elements as soft masks: the mask's children, drawn in the masked element's user
 * space (or its bounding box with maskContentUnits="objectBoundingBox") and clipped to the mask's
 * region (by default 10% beyond the bounding box on every side), mask what the element draws by
 * their luminance or, with mask-type="alpha", by their alpha. Masks are expanded through the
 * reference guard.
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

    /** @throws ParseException for a mask that masks itself, directly or through other references */
    public function apply(Canvas $canvas, string $id, Element $element, Style $style): void
    {
        $mask = $this->context->file->byId($id);
        if (null === $mask || 'mask' !== $mask->name) {
            return;
        }
        $bounds = $this->clips->bounds($element, $style) ?? new Box(0.0, 0.0, 0.0, 0.0);
        $this->context->guard->within('mask', $id, function () use ($canvas, $mask, $bounds): void {
            $this->draw($canvas, $mask, $bounds);
        });
    }

    /** Draws a mask already entered in the guard and sets it as the soft mask. */
    private function draw(Canvas $canvas, Element $mask, Box $bounds): void
    {
        $region   = $this->region($mask, $bounds);
        $template = $this->context->document->template(
            new Dimensions(1.0, 1.0),
            Origin::BottomLeft,
            new Box(-self::HUGE, -self::HUGE, 2.0 * self::HUGE, 2.0 * self::HUGE),
        );
        $content = $template->canvas();
        Painter::clipTo(
            $content,
            Path::rect($region->x, $region->y, $region->width, $region->height),
            FillRule::NonZero,
        );
        $content->concat(Units::of($mask->attribute('maskContentUnits'), Units::UserSpaceOnUse)->boxMatrix($bounds));
        $maskStyle = Style::root()->derive($mask, $this->context->sheet);
        ($this->children)($content, $mask, $maskStyle);
        $canvas->setSoftMask($template, $maskStyle->maskAlpha() ? SoftMaskType::Alpha : SoftMaskType::Luminosity);
    }

    /** The mask's region, in user space or as fractions of the bounding box (the default). */
    private function region(Element $mask, Box $bounds): Box
    {
        return Units::of($mask->attribute('maskUnits'), Units::ObjectBoundingBox)->region(
            $mask->attribute('x') ?? '-10%',
            $mask->attribute('y') ?? '-10%',
            $mask->attribute('width') ?? '120%',
            $mask->attribute('height') ?? '120%',
            $bounds,
            $this->context->viewportWidth,
            $this->context->viewportHeight,
        );
    }
}
