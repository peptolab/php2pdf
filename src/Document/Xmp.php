<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use DateTimeImmutable;
use DateTimeInterface;

use function htmlspecialchars;
use function implode;
use function md5;
use function str_repeat;
use function substr;

use const ENT_QUOTES;
use const ENT_XML1;

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
        private readonly DateTimeImmutable $date,
    ) {}

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, encoding: 'UTF-8');
    }

    private static function uuidFromSeed(string $seed): string
    {
        $hex = md5($seed);

        return (
            substr($hex, offset: 0, length: 8)
                . '-'
                . substr($hex, offset: 8, length: 4)
                . '-4'
                . substr($hex, offset: 13, length: 3)
                . '-a'
                . substr($hex, offset: 17, length: 3)
                . '-'
                . substr($hex, offset: 20, length: 12)
        );
    }

    public function addDescription(string $rdfDescription): void
    {
        $this->extra[] = $rdfDescription;
    }

    public function build(): string
    {
        $date     = $this->date->format(DateTimeInterface::ATOM);
        $title    = $this->info->title();
        $author   = $this->info->author();
        $subject  = $this->info->subject();
        $keywords = $this->info->keywords();
        $creator  = $this->info->creator();
        $producer = self::escape($this->producer);
        $uuid     = self::uuidFromSeed(($title ?? '') . "|{$date}|{$this->producer}");

        $dc = '';
        if (null !== $title) {
            $dc .=
                '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">'
                . self::escape($title)
                . '</rdf:li></rdf:Alt></dc:title>';
        }
        if (null !== $author) {
            $dc .= '<dc:creator><rdf:Seq><rdf:li>' . self::escape($author) . '</rdf:li></rdf:Seq></dc:creator>';
        }
        if (null !== $subject) {
            $dc .=
                '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">'
                . self::escape($subject)
                . '</rdf:li></rdf:Alt></dc:description>';
        }

        $pdf = "<pdf:Producer>{$producer}</pdf:Producer>";
        if (null !== $keywords) {
            $pdf .= '<pdf:Keywords>' . self::escape($keywords) . '</pdf:Keywords>';
        }

        $xmp =
            "<xmp:CreateDate>{$date}</xmp:CreateDate><xmp:ModifyDate>{$date}</xmp:ModifyDate>"
            . "<xmp:MetadataDate>{$date}</xmp:MetadataDate>";
        if (null !== $creator) {
            $xmp .= '<xmp:CreatorTool>' . self::escape($creator) . '</xmp:CreatorTool>';
        }

        $descriptions = [
            "<rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">{$dc}</rdf:Description>",
            "<rdf:Description rdf:about=\"\" xmlns:pdf=\"http://ns.adobe.com/pdf/1.3/\">{$pdf}</rdf:Description>",
            "<rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">{$xmp}</rdf:Description>",
            '<rdf:Description rdf:about="" xmlns:xmpMM="http://ns.adobe.com/xap/1.0/mm/">'
                . "<xmpMM:DocumentID>uuid:{$uuid}</xmpMM:DocumentID>"
                . "<xmpMM:InstanceID>uuid:{$uuid}</xmpMM:InstanceID>"
                . '</rdf:Description>',
            ...$this->extra,
        ];
        $padding = str_repeat(' ', times: 2048);
        $body    = implode("\n", $descriptions);

        return (
            "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
                . "<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
                . "<rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
                . "{$body}\n"
                . "</rdf:RDF>\n"
                . "</x:xmpmeta>\n"
                . "{$padding}\n"
                . '<?xpacket end="w"?>'
        );
    }
}
