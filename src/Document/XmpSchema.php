<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use function htmlspecialchars;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * An XMP schema PDF/A does not predefine, as its extension schema declares it (ISO 19005-2,
 * 6.6.2.3.2): its name, namespace and prefix, and each property's name, value type and meaning.
 * Every property a document's XMP uses outside the predefined schemas needs one.
 *
 * @internal
 */
final readonly class XmpSchema
{
    /**
     * @param list<array{0: string, 1: string, 2: string}> $properties each property's name, value
     *                                                                type (Text, Closed Choice of
     *                                                                Text...) and description
     */
    public function __construct(
        public string $name,
        public string $namespace,
        public string $prefix,
        public array $properties,
    ) {}

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, encoding: 'UTF-8');
    }

    /** The schema as an item of pdfaExtension:schemas. */
    public function declaration(): string
    {
        $properties = '';
        foreach ($this->properties as [$name, $type, $description]) {
            $properties .=
                '<rdf:li rdf:parseType="Resource">'
                . '<pdfaProperty:name>'
                . self::escape($name)
                . '</pdfaProperty:name>'
                . '<pdfaProperty:valueType>'
                . self::escape($type)
                . '</pdfaProperty:valueType>'
                . '<pdfaProperty:category>external</pdfaProperty:category>'
                . '<pdfaProperty:description>'
                . self::escape($description)
                . '</pdfaProperty:description>'
                . '</rdf:li>';
        }

        return (
            '<rdf:li rdf:parseType="Resource">'
                . '<pdfaSchema:schema>'
                . self::escape($this->name)
                . '</pdfaSchema:schema>'
                . '<pdfaSchema:namespaceURI>'
                . self::escape($this->namespace)
                . '</pdfaSchema:namespaceURI>'
                . '<pdfaSchema:prefix>'
                . self::escape($this->prefix)
                . '</pdfaSchema:prefix>'
                . "<pdfaSchema:property><rdf:Seq>{$properties}</rdf:Seq></pdfaSchema:property>"
                . '</rdf:li>'
        );
    }
}
