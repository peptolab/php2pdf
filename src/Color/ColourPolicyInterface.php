<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Exception\ConformanceException;

/**
 * What a document asks of the colour drawn into it: the checks a conformance level such as PDF/X
 * makes, and the colour spaces the document defines. Every check passes when the document makes
 * none.
 *
 * @internal
 */
interface ColourPolicyInterface
{
    /**
     * Checks a device colour space the document can reproduce.
     *
     * @param string $space DeviceGray, DeviceRGB or DeviceCMYK
     * @param string $what  what uses it, for the error message
     *
     * @throws ConformanceException when the space is not allowed here
     */
    public function assertDeviceColour(string $space, string $what = 'colours'): void;

    /**
     * Checks a device colour space an imported page uses.
     *
     * @throws ConformanceException when the space is not allowed in imported artwork
     */
    public function assertImportedColour(string $space, int $page): void;

    /**
     * Refuses colour until the output intent the document needs is set.
     *
     * @throws ConformanceException when the document needs an output intent and has none
     */
    public function assertOutputIntent(): void;

    /** The colour space transparency groups blend in. */
    public function blendingColorSpace(): Name|Reference;

    /** Whether the document checks colour at all, so imported artwork needs scanning. */
    public function checksColour(): bool;

    /** The ICCBased colour space DeviceRGB is interpreted in, when the document sets one. */
    public function defaultRgb(): ?Reference;
}
