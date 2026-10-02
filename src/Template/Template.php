<?php

declare(strict_types=1);

namespace Php2Pdf\Template;

use Override;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Canvas\Resources;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Core\DeferredInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Origin;

/**
 * Reusable artwork: a form XObject with its own canvas, drawn once and placed any number of times
 * through the fit engine. Its content is written when the document closes, so it can be drawn
 * on until then.
 *
 * @api
 */
final class Template implements PlaceableInterface, DeferredInterface
{
    private readonly Reference $reference;
    private readonly Canvas $canvas;
    private readonly Resources $resources;

    private bool $transparencyGroup = false;

    /**
     * @internal
     *
     * @param Box|null $bbox the form's bounding box when it differs from [0 0 width height]
     */
    public function __construct(
        private readonly Objects $objects,
        private readonly ColourPolicyInterface $colour,
        private readonly Dimensions $size,
        private readonly Origin $origin,
        private readonly ?Box $bbox = null,
    ) {
        $this->reference = $objects->allocate();
        $this->resources = new Resources($colour->defaultRgb());
        $this->canvas    = new Canvas(
            $objects,
            $colour,
            $this->resources,
            $size->height,
            $origin->isTopDown(),
            inheritsState: true,
        );
        $objects->defer($this);
    }

    /**
     * Makes the form a transparency group, so group opacity and blend modes applied when it is
     * placed composite its content as one object.
     */
    public function asTransparencyGroup(): self
    {
        $this->transparencyGroup = true;

        return $this;
    }

    /**
     * The form's bounding box, [llx lly urx ury] in its own space.
     *
     * @internal
     *
     * @return list<float>
     */
    public function boundingBox(): array
    {
        return $this->bbox?->toRect() ?? [0.0, 0.0, $this->size->width, $this->size->height];
    }

    public function canvas(): Canvas
    {
        return $this->canvas;
    }

    #[Override]
    public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void
    {
        $canvas->objects()->assertOwns($this->objects, 'A template');
        $canvas->drawXObject($this->reference, $matrix);
    }

    /** @internal */
    #[Override]
    public function finalize(): void
    {
        $dict = Dictionary::of([
            'Type'      => new Name('XObject'),
            'Subtype'   => new Name('Form'),
            'FormType'  => 1,
            'BBox'      => $this->boundingBox(),
            'Resources' => $this->resources->toDictionary(),
        ]);
        if ($this->transparencyGroup) {
            $dict->set('Group', Dictionary::of([
                'S'  => new Name('Transparency'),
                'CS' => $this->colour->blendingColorSpace(),
                'I'  => true,
            ]));
        }
        $this->objects->write($this->reference, new Stream($dict, $this->canvas->finish()));
    }

    public function height(): float
    {
        return $this->size->height;
    }

    #[Override]
    public function naturalSize(?float $dpi = null): Dimensions
    {
        return $this->size;
    }

    /**
     * The document objects the template is written with.
     *
     * @internal
     */
    public function objects(): Objects
    {
        return $this->objects;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }

    /** @internal */
    public function reference(): Reference
    {
        return $this->reference;
    }

    public function width(): float
    {
        return $this->size->width;
    }
}
