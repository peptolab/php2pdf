<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Closure;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\OpenType\Cff\CffSubsetter;
use Php2Pdf\Font\OpenType\Cff\CffTable;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\OpenType\TrueTypeSubsetter;

use function array_map;
use function chr;
use function implode;
use function md5;
use function ord;
use function round;
use function sprintf;
use function strlen;

/**
 * Writes an embedded font as a PDF Type 0 font: the font program subset to the glyphs a document
 * used, its descriptor and widths, and the ToUnicode map its text is copied with. The glyphs are
 * renumbered in the order of the codes the document wrote them with, so a code is the subset's
 * glyph id (and CID) and the subset holds only the glyphs used.
 *
 * @internal
 */
final readonly class Type0FontWriter
{
    private float $scale;

    /** @param Closure(int): int $advance a glyph's advance in thousandths of an em */
    public function __construct(
        private OpenTypeFont $program,
        private Objects $objects,
        private FontRegistry $registry,
        private Closure $advance,
    ) {
        $this->scale = 1000.0 / $program->unitsPerEm;
    }

    /**
     * Writes the font at its reference.
     *
     * @param list<int>             $glyphs    the glyph each code stands for, by code; code 0 is .notdef
     * @param array<int, list<int>> $toUnicode the text each code stands for
     *
     * @throws ParseException
     */
    public function write(Reference $reference, array $glyphs, array $toUnicode): void
    {
        $writer   = $this->objects;
        $program  = $this->program;
        $baseFont = "{$this->subsetTag($glyphs)}+{$program->postScriptName()}";
        $cff      = $program->isCff;

        $fontFile = $cff ? $this->cffStream($glyphs) : $this->subsetStream($glyphs);

        $descriptor = $writer->add(Dictionary::of([
            'Type'                           => new Name('FontDescriptor'),
            'FontName'                       => new Name($baseFont),
            'Flags'                          => $this->flags(),
            'FontBBox'                       => array_map(
                fn(int $v): int => (int) round($v * $this->scale),
                $program->metrics->bbox,
            ),
            'ItalicAngle'                    => $program->metrics->italicAngle,
            'Ascent'                         => (int) round($program->metrics->ascender * $this->scale),
            'Descent'                        => (int) round($program->metrics->descender * $this->scale),
            'CapHeight'                      => (int) round($program->metrics->capHeight * $this->scale),
            'XHeight'                        => (int) round($program->metrics->xHeight * $this->scale),
            'StemV'                          => $this->stemV(),
            $cff ? 'FontFile3' : 'FontFile2' => $fontFile,
        ]));

        $descendant = $writer->add(Dictionary::of([
            'Type'           => new Name('Font'),
            'Subtype'        => new Name($cff ? 'CIDFontType0' : 'CIDFontType2'),
            'BaseFont'       => new Name($baseFont),
            'CIDSystemInfo'  => Dictionary::of(['Registry' => 'Adobe', 'Ordering' => 'Identity', 'Supplement' => 0]),
            'FontDescriptor' => $descriptor,
            'DW'             => 1000,
            'W'              => [0, array_map($this->advance, $glyphs)],
            'CIDToGIDMap'    => $cff ? null : new Name('Identity'),
        ]));
        $toUnicodeRef = $writer->add(Stream::of(ToUnicode::build($toUnicode)));

        $writer->write($reference, Dictionary::of([
            'Type'            => new Name('Font'),
            'Subtype'         => new Name('Type0'),
            'BaseFont'        => new Name($baseFont),
            'Encoding'        => new Name('Identity-H'),
            'DescendantFonts' => [$descendant],
            'ToUnicode'       => $toUnicodeRef,
        ]));
    }

    /**
     * The CFF outlines subset to the glyphs used, or the whole font program when they cannot be
     * subset; the font registry then records a warning. A variable font's CFF2 outlines are
     * converted to CFF at their default instance first; when that fails the font cannot be used,
     * since few readers draw CFF2.
     *
     * @param list<int> $glyphs by code
     *
     * @throws ParseException
     */
    private function cffStream(array $glyphs): Reference
    {
        try {
            $data    = CffSubsetter::subsetTable($this->cffTable(), $glyphs);
            $subtype = 'CIDFontType0C';
        } catch (PdfException $e) {
            if (null === $this->program->table('CFF ')) {
                throw new ParseException(sprintf(
                    'Variable font "%s" cannot be embedded: its CFF2 outlines could not be converted: %s',
                    $this->program->postScriptName(),
                    $e->getMessage(),
                ), previous: $e);
            }
            $this->registry
                ->warn(sprintf(
                    'Font "%s" is embedded whole because its CFF outlines could not be subset: %s',
                    $this->program->postScriptName(),
                    $e->getMessage(),
                ));
            $data    = $this->program->data();
            $subtype = 'OpenType';
        }

        return $this->objects->add(new Stream(Dictionary::of(['Subtype' => new Name($subtype)]), $data));
    }

    /**
     * The font's CFF outlines: the CFF table, or a variable font's CFF2 outlines at their default
     * instance.
     *
     * @throws ParseException
     */
    private function cffTable(): CffTable
    {
        $cff = $this->program->table('CFF ');
        if (null !== $cff) {
            return CffTable::parse($cff);
        }
        $cff2 = $this->program->table('CFF2') ?? throw new ParseException('The font has no CFF or CFF2 table.');

        return CffTable::fromCff2($cff2, $this->program->postScriptName());
    }

    /** The descriptor's flags: nonsymbolic (32), and fixed pitch, serif and italic as the font is. */
    private function flags(): int
    {
        $metrics = $this->program->metrics;
        $flags   = 32;
        if ($metrics->isFixedPitch) {
            $flags |= 1;
        }
        if ($metrics->isSerif) {
            $flags |= 2;
        }
        if ($metrics->isItalic || 0.0 !== $metrics->italicAngle) {
            $flags |= 64;
        }

        return $flags;
    }

    /** A common estimate of the stem width: the real ones live in the outlines, which readers do not need. */
    private function stemV(): int
    {
        return (int) round(50 + ($this->program->metrics->weightClass / 65.0) ** 2);
    }

    /**
     * The TrueType outlines subset to the glyphs used.
     *
     * @param list<int> $glyphs by code
     *
     * @throws ParseException
     */
    private function subsetStream(array $glyphs): Reference
    {
        $data = TrueTypeSubsetter::subset($this->program, $glyphs);

        return $this->objects->add(new Stream(Dictionary::of(['Length1' => strlen($data)]), $data));
    }

    /**
     * Six uppercase letters derived from the glyphs in code order, so identical input yields
     * identical tags.
     *
     * @param list<int> $glyphs
     */
    private function subsetTag(array $glyphs): string
    {
        $hash = md5($this->program->postScriptName() . ':' . implode(',', $glyphs), binary: true);
        $tag  = '';
        for ($i = 0; $i < 6; ++$i) {
            $tag .= chr(65 + (ord($hash[$i]) % 26));
        }

        return $tag;
    }
}
