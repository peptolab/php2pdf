<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Document\Dimensions;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;
use PhpPdf\Geometry\Origin;
use PhpPdf\Template\Template;

use function array_key_exists;
use function ltrim;
use function str_starts_with;
use function trim;

/**
 * Walks the element tree and draws it on a template's canvas: groups, transforms, clip paths,
 * group opacity through transparency groups, <use> and <symbol>, nested viewports, shapes, text
 * and images.
 *
 * @internal
 */
final class Renderer
{
    private const array SKIPPED = [
        'defs'           => true,
        'clipPath'       => true,
        'linearGradient' => true,
        'radialGradient' => true,
        'style'          => true,
        'title'          => true,
        'desc'           => true,
        'metadata'       => true,
        'symbol'         => true,
        'marker'         => true,
        'mask'           => true,
        'pattern'        => true,
        'filter'         => true,
        'script'         => true,
        'font'           => true,
        'cursor'         => true,
        'view'           => true,
    ];

    private const float HUGE = 100_000.0;

    private readonly Painter $painter;
    private readonly TextRenderer $text;
    private readonly ClipBuilder $clips;
    private readonly ImageRenderer $images;
    private int $depth = 0;

    /** @var array<string, true> ids currently being expanded by <use>, to break cycles */
    private array $expanding = [];

    public function __construct(
        private readonly Context $context,
    ) {
        $this->painter = new Painter($context);
        $this->text    = new TextRenderer($context, $this->painter);
        $this->clips   = new ClipBuilder($context);
        $this->images  = new ImageRenderer($context);
    }

    public function render(): Template
    {
        $root = $this->context->file->root();
        [$width, $height] = Viewport::intrinsicSize($root);
        $template = $this->context->document->template(
            new Dimensions($width * Length::PT_PER_PX, $height * Length::PT_PER_PX),
            Origin::BottomLeft,
        );
        $canvas = $template->canvas();
        $canvas->save();
        $canvas->concat(new Matrix(Length::PT_PER_PX, 0.0, 0.0, -Length::PT_PER_PX, 0.0, $height * Length::PT_PER_PX));
        $this->context->viewportWidth  = $width;
        $this->context->viewportHeight = $height;
        $style                         = Style::root()->derive($root, $this->context->sheet);
        $this->viewport($canvas, $root, $style, 0.0, 0.0, $width, $height);
        $canvas->restore();

        return $template;
    }

    private function children(Canvas $canvas, Element $element, Style $style): void
    {
        foreach ($element->elements() as $child) {
            $this->element($canvas, $child, $style);
        }
    }

    private function clipViewport(Canvas $canvas, Style $style, float $x, float $y, float $width, float $height): void
    {
        if ($style->overflowVisible() || $width <= 0.0 || $height <= 0.0) {
            return;
        }
        Painter::clipTo($canvas, PathData::rect($x, $y, $width, $height), $style->clipRule());
    }

    /** Draws an element's own content after its transform: clip, then shape, text, image or children. */
    private function content(Canvas $canvas, Element $element, Style $style): void
    {
        $clipId = $style->clipPathId();
        if (null !== $clipId) {
            $this->clips->apply($canvas, $clipId, $element, $style, $this->expanding);
        }
        match (true) {
            Shapes::isShape($element->name) => $this->shape($canvas, $element, $style),
            'text' === $element->name => $this->text->render($canvas, $element, $style),
            'image' === $element->name => $this->images->render($canvas, $element, $style),
            'use' === $element->name => $this->use($canvas, $element, $style),
            'svg' === $element->name => $this->nestedSvg($canvas, $element, $style),
            'switch' === $element->name => $this->switch($canvas, $element, $style),
            'g' === $element->name, 'a' === $element->name => $this->children($canvas, $element, $style),
            default => $this->context->warn("<{$element->name}>"),
        };
    }

    /** Draws an element in the current user space, applying its transform, clip and opacity. */
    private function element(Canvas $canvas, Element $element, Style $parent): void
    {
        if (array_key_exists($element->name, self::SKIPPED)) {
            return;
        }
        $style = $parent->derive($element, $this->context->sheet);
        if (! $style->isDisplayed() || $this->depth > 64) {
            return;
        }
        foreach ($style->unsupported() as $feature) {
            $this->context->warn($feature);
        }
        ++$this->depth;
        $canvas->save();
        $transform = TransformParser::parse($element->attribute('transform'));
        if (! $transform->isIdentity()) {
            $canvas->concat($transform);
        }
        $opacity = $style->opacity();
        if ($opacity < 1.0) {
            $this->group($canvas, $element, $style, $opacity);
        }
        if ($opacity >= 1.0) {
            $this->content($canvas, $element, $style);
        }
        $canvas->restore();
        --$this->depth;
    }

    /** Draws the element's content into a transparency group and composites it with group opacity. */
    private function group(Canvas $canvas, Element $element, Style $style, float $opacity): void
    {
        $group = $this->context
            ->document
            ->template(
                new Dimensions(1.0, 1.0),
                Origin::BottomLeft,
                new Box(-self::HUGE, -self::HUGE, 2.0 * self::HUGE, 2.0 * self::HUGE),
            )
            ->asTransparencyGroup();
        $this->content($group->canvas(), $element, $style);
        $canvas->setOpacity($opacity);
        $canvas->drawXObject($group->reference(), Matrix::identity());
    }

    private function nestedSvg(Canvas $canvas, Element $element, Style $style): void
    {
        $x      = $this->context->length($element->attribute('x'), 'x', $style) ?? 0.0;
        $y      = $this->context->length($element->attribute('y'), 'y', $style) ?? 0.0;
        $width  = $this->context->length($element->attribute('width') ?? '100%', 'x', $style) ?? 0.0;
        $height = $this->context->length($element->attribute('height') ?? '100%', 'y', $style) ?? 0.0;
        $this->clipViewport($canvas, $style, $x, $y, $width, $height);
        $this->viewport($canvas, $element, $style, $x, $y, $width, $height);
    }

    private function shape(Canvas $canvas, Element $element, Style $style): void
    {
        $path = Shapes::path($element, $style, $this->context);
        if (null === $path) {
            return;
        }
        $this->painter->paint($canvas, $path, $style);
    }

    private function switch(Canvas $canvas, Element $element, Style $style): void
    {
        foreach ($element->elements() as $child) {
            if (array_key_exists($child->name, self::SKIPPED) || null !== $child->attribute('requiredExtensions')) {
                continue;
            }
            $this->element($canvas, $child, $style);

            return;
        }
    }

    private function use(Canvas $canvas, Element $use, Style $style): void
    {
        $href = $use->href();
        if (null === $href || ! str_starts_with(trim($href), '#')) {
            $this->context->warn('external <use> references');

            return;
        }
        $id = ltrim(trim($href), characters: '#');
        if (array_key_exists($id, $this->expanding)) {
            return;
        }
        $target = $this->context->file->byId($id);
        if (null === $target) {
            return;
        }
        $this->expanding[$id] = true;
        $canvas->save();
        $x = $this->context->length($use->attribute('x'), 'x', $style) ?? 0.0;
        $y = $this->context->length($use->attribute('y'), 'y', $style) ?? 0.0;
        if (0.0 !== $x || 0.0 !== $y) {
            $canvas->translate($x, $y);
        }
        $isViewport = 'symbol' === $target->name || 'svg' === $target->name;
        if ($isViewport) {
            $this->useViewport($canvas, $use, $target, $style);
        }
        if (! $isViewport) {
            $this->element($canvas, $target, $style);
        }
        $canvas->restore();
        unset($this->expanding[$id]);
    }

    /** A <symbol> or <svg> referenced by <use>: sized by the use element, then by itself. */
    private function useViewport(Canvas $canvas, Element $use, Element $target, Style $parent): void
    {
        $style = $parent->derive($target, $this->context->sheet);
        $width = $this->context->length(
            $use->attribute('width') ?? $target->attribute('width') ?? '100%',
            'x',
            $style,
        );
        $height = $this->context->length(
            $use->attribute('height') ?? $target->attribute('height') ?? '100%',
            'y',
            $style,
        );
        $x = 'svg' === $target->name ? $this->context->length($target->attribute('x'), 'x', $style) ?? 0.0 : 0.0;
        $y = 'svg' === $target->name ? $this->context->length($target->attribute('y'), 'y', $style) ?? 0.0 : 0.0;
        $canvas->save();
        $transform = TransformParser::parse($target->attribute('transform'));
        if (! $transform->isIdentity()) {
            $canvas->concat($transform);
        }
        $this->clipViewport($canvas, $style, $x, $y, $width ?? 0.0, $height ?? 0.0);
        $this->viewport($canvas, $target, $style, $x, $y, $width ?? 0.0, $height ?? 0.0);
        $canvas->restore();
    }

    /** Establishes a viewport at x, y of the given size, mapping the element's viewBox into it. */
    private function viewport(
        Canvas $canvas,
        Element $element,
        Style $style,
        float $x,
        float $y,
        float $width,
        float $height,
    ): void {
        if ($width <= 0.0 || $height <= 0.0) {
            return;
        }
        $savedWidth  = $this->context->viewportWidth;
        $savedHeight = $this->context->viewportHeight;
        $canvas->save();
        $viewBox = Viewport::viewBox($element);
        $mapping = Viewport::mapping($viewBox, $width, $height, $element->attribute('preserveAspectRatio'));
        $canvas->concat(Matrix::translation($x, $y)->after($mapping));
        $this->context->viewportWidth  = null === $viewBox ? $width : $viewBox[2];
        $this->context->viewportHeight = null === $viewBox ? $height : $viewBox[3];
        $this->children($canvas, $element, $style);
        $canvas->restore();
        $this->context->viewportWidth  = $savedWidth;
        $this->context->viewportHeight = $savedHeight;
    }
}
