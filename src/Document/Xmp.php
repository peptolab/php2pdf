<?php

declare(strict_types=1);

namespace PhpPdf\Document;

/**
 * Builds a minimal XMP metadata packet mirroring the Info dictionary.
 *
 * @internal
 */
final class Xmp
{
    /** @var list<string> extra RDF descriptions, e.g. for PDF/X conformance */
    private array $extra = [];

    public function __construct(
        private readonly Info $info,
        private readonly string $producer,
        private readonly \DateTimeImmutable $date,
    ) {
    }

    public function addDescription(string $rdfDescription): void
    {
        $this->extra[] = $rdfDescription;
    }

    public function build(): string
    {
        $date  = $this->date->format(\DateTimeInterface::ATOM);
        $title = $this->info->title();
        $seed  = ($title ?? '') . '|' . $date . '|' . $this->producer;
        $uuid  = self::uuidFromSeed($seed);

        $dc = '';
        if (null !== $title) {
            $dc .= '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . self::escape($title) . '</rdf:li></rdf:Alt></dc:title>';
        }
        if (null !== $this->info->author()) {
            $dc .= '<dc:creator><rdf:Seq><rdf:li>' . self::escape($this->info->author()) . '</rdf:li></rdf:Seq></dc:creator>';
        }
        if (null !== $this->info->subject()) {
            $dc .= '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">'
                . self::escape($this->info->subject())
                . '</rdf:li></rdf:Alt></dc:description>';
        }

        $pdf = '<pdf:Producer>' . self::escape($this->producer) . '</pdf:Producer>';
        if (null !== $this->info->keywords()) {
            $pdf .= '<pdf:Keywords>' . self::escape($this->info->keywords()) . '</pdf:Keywords>';
        }

        $xmp = '<xmp:CreateDate>' . $date . '</xmp:CreateDate><xmp:ModifyDate>' . $date . '</xmp:ModifyDate>'
            . '<xmp:MetadataDate>' . $date . '</xmp:MetadataDate>';
        if (null !== $this->info->creator()) {
            $xmp .= '<xmp:CreatorTool>' . self::escape($this->info->creator()) . '</xmp:CreatorTool>';
        }

        $descriptions = [
            '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">' . $dc . '</rdf:Description>',
            '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">' . $pdf . '</rdf:Description>',
            '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/">' . $xmp . '</rdf:Description>',
            '<rdf:Description rdf:about="" xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/">'
                . '<xmpMM:DocumentID>uuid:' . $uuid . '</xmpMM:DocumentID>'
                . '<xmpMM:InstanceID>uuid:' . $uuid . '</xmpMM:InstanceID>'
                . '</rdf:Description>',
            ...$this->extra,
        ];

        return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/">' . "\n"
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
            . implode("\n", $descriptions) . "\n"
            . '</rdf:RDF>' . "\n"
            . '</x:xmpmeta>' . "\n"
            . str_repeat(' ', 2048) . "\n"
            . '<?xpacket end="w"?>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function uuidFromSeed(string $seed): string
    {
        $hex = md5($seed);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-a'
            . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }
}
