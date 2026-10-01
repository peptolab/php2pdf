<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;

use function count;

/**
 * The resource dictionary of a page or form XObject. Hands out resource names and remembers which
 * indirect objects they map to.
 *
 * @internal
 */
final class Resources
{
    /** @var array<string, array<string, Reference>> category => name => reference */
    private array $entries = [];

    /** @var array<string, array<int, string>> category => object id => name */
    private array $names = [];

    /** @param Reference|null $defaultRgb an ICCBased colour space DeviceRGB is interpreted in */
    public function __construct(
        private readonly ?Reference $defaultRgb = null,
    ) {}

    public function colorSpace(Reference $ref): string
    {
        return $this->register('ColorSpace', 'CS', $ref);
    }

    public function extGState(Reference $ref): string
    {
        return $this->register('ExtGState', 'GS', $ref);
    }

    public function font(Reference $ref): string
    {
        return $this->register('Font', 'F', $ref);
    }

    public function pattern(Reference $ref): string
    {
        return $this->register('Pattern', 'P', $ref);
    }

    public function shading(Reference $ref): string
    {
        return $this->register('Shading', 'Sh', $ref);
    }

    public function toDictionary(): Dictionary
    {
        $dict = new Dictionary();
        $dict->set('ProcSet', [
            new Name('PDF'),
            new Name('Text'),
            new Name('ImageB'),
            new Name('ImageC'),
            new Name('ImageI'),
        ]);
        foreach ($this->entries as $category => $names) {
            $dict->set($category, new Dictionary($names));
        }
        if (null !== $this->defaultRgb) {
            $spaces = new Dictionary($this->entries['ColorSpace'] ?? []);
            $spaces->set('DefaultRGB', $this->defaultRgb);
            $dict->set('ColorSpace', $spaces);
        }

        return $dict;
    }

    public function xObject(Reference $ref): string
    {
        return $this->register('XObject', 'X', $ref);
    }

    private function register(string $category, string $prefix, Reference $ref): string
    {
        $existing = $this->names[$category][$ref->id] ?? null;
        if (null !== $existing) {
            return $existing;
        }
        $name = $prefix . (count($this->entries[$category] ?? []) + 1);

        $this->entries[$category][$name]  = $ref;
        $this->names[$category][$ref->id] = $name;

        return $name;
    }
}
