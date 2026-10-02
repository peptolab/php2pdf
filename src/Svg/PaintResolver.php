<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;

/**
 * Looks up what a fill or stroke paints with: a pattern or gradient its url() names, the colour
 * after the url() when the id is neither, or its colour. A pattern that paints nothing paints
 * nothing; it does not fall back to the colour.
 *
 * @internal
 */
final readonly class PaintResolver
{
    public function __construct(
        private Context $context,
        private PatternBuilder $patterns,
    ) {}

    /**
     * @param Box $bounds the box the paint covers, for paint servers in bounding-box units
     *
     * @throws ParseException
     */
    public function resolve(Paint $paint, Box $bounds, Style $style): ResolvedPaint
    {
        if ($paint->isNone()) {
            return ResolvedPaint::none();
        }
        $id     = $paint->serverId;
        $server = null === $id ? null : $this->context->file->byId($id);
        if (null !== $id && 'pattern' === $server?->name) {
            $tile = $this->patterns->tile($id, $server, $bounds);

            return null === $tile ? ResolvedPaint::none() : ResolvedPaint::pattern($tile);
        }
        $gradient = null === $id
            ? null
            : $this->context->servers->gradient($id, $bounds, $style, $this->context->sheet);
        if (null !== $gradient) {
            return ResolvedPaint::gradient($gradient[0], $gradient[1]);
        }

        return null === $paint->color ? ResolvedPaint::none() : ResolvedPaint::colour($paint->color);
    }
}
