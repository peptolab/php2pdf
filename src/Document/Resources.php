<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;

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
        $dict->set('ProcSet', [new Name('PDF'), new Name('Text'), new Name('ImageB'), new Name('ImageC'), new Name('ImageI')]);
        foreach ($this->entries as $category => $names) {
            $dict->set($category, new Dictionary($names));
        }

        return $dict;
    }

    public function xObject(Reference $ref): string
    {
        return $this->register('XObject', 'X', $ref);
    }

    private function register(string $category, string $prefix, Reference $ref): string
    {
        if (isset($this->names[$category][$ref->id])) {
            return $this->names[$category][$ref->id];
        }
        $name = $prefix . (count($this->entries[$category] ?? []) + 1);

        $this->entries[$category][$name]  = $ref;
        $this->names[$category][$ref->id] = $name;

        return $name;
    }
}
