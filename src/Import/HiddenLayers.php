<?php

declare(strict_types=1);

namespace PhpPdf\Import;

/**
 * What happens to the layers (optional content groups) of imported pages that the source file
 * switches off by default.
 *
 * @api
 */
enum HiddenLayers
{
    /** Keep them as layers, still off, which viewers can switch on. macOS Preview shows them. */
    case Keep;

    /** Remove their content, so no viewer shows them; the visible layers stay switchable. */
    case Remove;
}
