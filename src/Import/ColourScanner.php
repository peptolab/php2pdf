<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function is_array;
use function spl_object_id;

/**
 * Finds the device colour spaces an imported page draws with: those named in its resources (and
 * in the forms, images, shadings and patterns they hold, and the alternates of Separation and
 * DeviceN spaces), and those its content streams select with g, rg, k and their stroking
 * forms or in inline images. PDF/X checks them against the output intent.
 *
 * @internal
 */
final class ColourScanner
{
    /** Content operators that select a device colour space directly. */
    private const array OPERATORS = [
        'g'  => 'DeviceGray',
        'G'  => 'DeviceGray',
        'rg' => 'DeviceRGB',
        'RG' => 'DeviceRGB',
        'k'  => 'DeviceCMYK',
        'K'  => 'DeviceCMYK',
    ];

    /** Device colour space names, including the abbreviations inline images use. */
    private const array DEVICE = [
        'DeviceGray' => 'DeviceGray',
        'G'          => 'DeviceGray',
        'DeviceRGB'  => 'DeviceRGB',
        'RGB'        => 'DeviceRGB',
        'DeviceCMYK' => 'DeviceCMYK',
        'CMYK'       => 'DeviceCMYK',
    ];

    /** @var array<string, true> */
    private array $spaces = [];

    /** @var array<int, true> resources and streams already scanned */
    private array $seen = [];

    public function __construct(
        private readonly PdfReader $reader,
    ) {}

    /**
     * The device colour spaces a page uses.
     *
     * @return list<string> DeviceGray, DeviceRGB and DeviceCMYK, as found
     *
     * @throws ParseException
     */
    public function scan(PdfPage $page): array
    {
        $this->spaces = [];
        $this->seen   = [];
        $resources    = $page->resources();
        if (null !== $resources) {
            $this->resources($resources, 0);
        }
        $this->content($page->content());

        return array_keys($this->spaces);
    }

    /**
     * Records the device spaces a colour space object stands on.
     *
     * @throws ParseException
     */
    private function colourSpace(mixed $space, int $depth): void
    {
        $space = $this->reader->resolve($space);
        if ($space instanceof Name) {
            $this->device($space->value);

            return;
        }
        if (! is_array($space) || $depth > $this->reader->limits()->maxObjectNesting) {
            return;
        }
        $family = $this->reader->resolve($space[0] ?? null);
        if (! $family instanceof Name) {
            return;
        }
        match ($family->value) {
            'Separation'   => $this->colourSpace($space[2] ?? null, $depth + 1),
            'DeviceN'      => $this->colourSpace($space[2] ?? null, $depth + 1),
            'Indexed', 'I' => $this->colourSpace($space[1] ?? null, $depth + 1),
            'Pattern'      => $this->colourSpace($space[1] ?? null, $depth + 1),
            default        => null,
        };
    }

    /**
     * Records the device spaces a content stream selects, inline images included.
     *
     * @throws ParseException
     */
    private function content(string $content): void
    {
        $parser = new ContentStreamParser($content, $this->reader->limits()->maxObjectNesting);
        foreach ($parser->operations() as $operation) {
            $operator = $operation->operator;
            $space    = self::OPERATORS[$operator] ?? null;
            if (null !== $space) {
                $this->spaces[$space] = true;
            }
            $operand = $operation->operands[count($operation->operands) - 1] ?? null;
            if (('cs' === $operator || 'CS' === $operator) && $operand instanceof Name) {
                $this->device($operand->value);
            }
            $image = $operation->inlineImage['CS'] ?? $operation->inlineImage['ColorSpace'] ?? null;
            if ($image instanceof Name) {
                $this->device($image->value);
            }
        }
    }

    private function device(string $name): void
    {
        $device = self::DEVICE[$name] ?? null;
        if (null !== $device) {
            $this->spaces[$device] = true;
        }
    }

    /**
     * Scans a form, image, or pattern.
     *
     * @throws ParseException
     */
    private function drawing(mixed $object, int $depth): void
    {
        $object = $this->reader->resolve($object);
        $dict   = $object instanceof Stream ? $object->dictionary : $object;
        if (! $dict instanceof Dictionary) {
            return;
        }
        $subtype = $this->reader->get($dict, 'Subtype');
        if ($subtype instanceof Name && 'Image' === $subtype->value) {
            if (true !== $this->reader->get($dict, 'ImageMask')) {
                $this->colourSpace($dict->get('ColorSpace'), $depth + 1);
            }

            return;
        }
        if (2 === $this->reader->get($dict, 'PatternType')) {
            $this->shading($dict->get('Shading'), $depth + 1);

            return;
        }
        if (! $object instanceof Stream) {
            return;
        }
        $id = spl_object_id($object);
        if (array_key_exists($id, $this->seen)) {
            return;
        }
        $this->seen[$id] = true;
        $resources       = $this->reader->dict($dict, 'Resources');
        if (null !== $resources) {
            $this->resources($resources, $depth + 1);
        }
        $this->content($this->reader->decode($object));
    }

    /**
     * The values of a resource sub-dictionary, unresolved.
     *
     * @return list<mixed>
     *
     * @throws ParseException
     */
    private function entries(Dictionary $resources, string $key): array
    {
        $dict = $this->reader->dict($resources, $key);

        return null === $dict ? [] : array_values($dict->all());
    }

    /**
     * Scans a resource dictionary and everything it draws.
     *
     * @throws ParseException
     */
    private function resources(Dictionary $resources, int $depth): void
    {
        $id = spl_object_id($resources);
        if (array_key_exists($id, $this->seen) || $depth > $this->reader->limits()->maxObjectNesting) {
            return;
        }
        $this->seen[$id] = true;
        foreach ($this->entries($resources, 'ColorSpace') as $space) {
            $this->colourSpace($space, $depth);
        }
        foreach ($this->entries($resources, 'Shading') as $shading) {
            $this->shading($shading, $depth);
        }
        foreach ($this->entries($resources, 'Pattern') as $pattern) {
            $this->drawing($pattern, $depth);
        }
        foreach ($this->entries($resources, 'XObject') as $xObject) {
            $this->drawing($xObject, $depth);
        }
    }

    /** @throws ParseException */
    private function shading(mixed $shading, int $depth): void
    {
        $shading = $this->reader->resolve($shading);
        $dict    = $shading instanceof Stream ? $shading->dictionary : $shading;
        if ($dict instanceof Dictionary) {
            $this->colourSpace($dict->get('ColorSpace'), $depth + 1);
        }
    }
}
