<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use Override;
use PhpPdf\Document\Document;
use PhpPdf\Font\FontInterface;
use PhpPdf\Template\Template;

/**
 * Converts SVG to vector artwork in pure PHP: paths and basic shapes, transforms, viewBox and
 * nested viewports, fills and strokes with opacity, linear and radial gradients, clip paths,
 * group opacity, inline styles and simple style sheets, <use> with <symbol> and <defs>, images
 * (PNG and JPEG, embedded or by path), and text set through the font engine.
 *
 * Masks, filters, patterns and markers are skipped; warnings() lists what a conversion left out.
 *
 * @api
 */
final class NativeConverter implements SvgConverterInterface
{
    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param FontInterface|null $fallbackFont used for generic families (sans-serif, serif...) and fonts the
     *                                document's registry cannot find; without one such text is skipped
     */
    public function __construct(
        private readonly ?FontInterface $fallbackFont = null,
    ) {}

    #[Override]
    public function convert(SvgFile $svg, Document $document): Template
    {
        $context        = new Context($document, $svg, new FontResolver($document, $this->fallbackFont));
        $renderer       = new Renderer($context);
        $template       = $renderer->render();
        $this->warnings = $context->warnings();

        return $template;
    }

    /**
     * Features the most recent conversion skipped, such as "filter" or "<textPath>".
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
