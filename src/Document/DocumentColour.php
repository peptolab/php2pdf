<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Override;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;

/**
 * The document's colour: the PDF/X checks when it is PDF/X, its RGB profile as DefaultRGB, and
 * the colour space transparency groups blend in.
 *
 * @internal
 */
final readonly class DocumentColour implements ColourPolicyInterface
{
    public function __construct(
        private DocumentOptions $options,
        private ConformanceRules $conformance,
        private Objects $objects,
    ) {}

    #[Override]
    public function assertDeviceColour(string $space, string $what = 'colours'): void
    {
        $this->conformance->assertDeviceColour($space, $what);
    }

    #[Override]
    public function assertImportedColour(string $space, int $page): void
    {
        $this->conformance->assertImportedColour($space, $page);
    }

    #[Override]
    public function assertOutputIntent(): void
    {
        $this->conformance->assertOutputIntent();
    }

    /** The output intent's space under PDF/X or PDF/A, otherwise RGB. */
    #[Override]
    public function blendingColorSpace(): Name|Reference
    {
        if (null === $this->options->conformance()) {
            return new Name('DeviceRGB');
        }

        return match ($this->conformance->intent()->components ?? 3) {
            1       => new Name('DeviceGray'),
            4       => new Name('DeviceCMYK'),
            default => $this->defaultRgb() ?? new Name('DeviceRGB'),
        };
    }

    #[Override]
    public function checksColour(): bool
    {
        return null !== $this->options->conformance();
    }

    /** The ICCBased space of the RGB profile, when one was given. */
    #[Override]
    public function defaultRgb(): ?Reference
    {
        $profile = $this->options->rgbProfile;
        if (null === $profile) {
            return null;
        }
        $stream = $this->objects->shared(
            "iccstream:{$profile->key()}",
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );

        return $this->objects->shared(
            "icc:{$profile->key()}",
            /** @return array{Name, Reference} */
            static fn(): array => [new Name('ICCBased'), $stream],
        );
    }
}
