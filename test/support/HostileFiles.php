<?php

declare(strict_types=1);

namespace PhpPdfTest\Support;

use function count;
use function gzcompress;
use function implode;
use function pack;
use function sprintf;
use function str_repeat;
use function strlen;

/**
 * Small malformed or oversized files for robustness tests: decompression bombs, shared page
 * trees, bad cross-reference streams, oversized and inconsistent PNGs, deep and self-expanding SVG.
 */
final class HostileFiles
{
    /** SVG with $depth nested groups. */
    public static function deepSvg(int $depth): string
    {
        return (
            '<svg xmlns="http://www.w3.org/2000/svg">'
                . str_repeat('<g>', $depth)
                . str_repeat('</g>', $depth)
                . '</svg>'
        );
    }

    /** One page whose content stream inflates to $bytes zero bytes. */
    public static function flateBomb(int $bytes): string
    {
        $data = (string) gzcompress(str_repeat("\0", $bytes));

        return self::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R >>',
            sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($data), $data),
        ]);
    }

    /** A GIF header declaring the given size, enough for its dimensions to be read. */
    public static function gif(int $width, int $height): string
    {
        return 'GIF89a' . pack('vv', $width, $height) . "\x00\x00\x00;";
    }

    /**
     * A valid one-page PDF followed by a second version of its page tree with two pages, which
     * only a reader that rebuilds the cross-reference by scanning the file will see.
     */
    public static function onePageUnlessRepaired(): string
    {
        return (
            self::pdf([
                '<< /Type /Catalog /Pages 2 0 R >>',
                '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
            ])
                . '4 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>
endobj
'
                . '2 0 obj
<< /Type /Pages /Kids [3 0 R 4 0 R] /Count 2 >>
endobj
'
        );
    }

    /** A document with $count plain pages. */
    public static function pages(int $count): string
    {
        $kids    = [];
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', ''];
        for ($i = 0; $i < $count; ++$i) {
            $kids[]    = sprintf('%d 0 R', $i + 3);
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>';
        }
        $objects[1] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), $count);

        return self::pdf($objects);
    }

    /**
     * A PDF of the given objects (numbered from 1; object 1 must be the catalog) with a classic
     * cross-reference table.
     *
     * @param list<string> $objects object bodies
     */
    public static function pdf(array $objects): string
    {
        $pdf     = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf       .= sprintf("%d 0 obj\n%s\nendobj\n", $i + 1, $body);
        }
        $xref = strlen($pdf);
        $pdf  .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return sprintf(
            "%strailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $pdf,
            count($objects) + 1,
            $xref,
        );
    }

    /** A PNG with only a header and an empty image data chunk. */
    public static function png(int $width, int $height, int $depth, int $type, int $interlace = 0): string
    {
        $chunk = static fn(string $name, string $data): string => (
            pack('N', strlen($data)) . $name . $data . pack('N', 0)
        );

        return (
            "\x89PNG\r\n\x1A\n"
                . $chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $type, 0, 0, $interlace))
                . $chunk('IDAT', (string) gzcompress(data: ''))
                . $chunk('IEND', data: '')
        );
    }

    /**
     * A page tree $levels deep in which every node lists the same child twice, so a walk that
     * follows every reference sees 2^$levels pages.
     */
    public static function sharedPageTree(int $levels): string
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>'];
        for ($level = 0; $level < $levels; ++$level) {
            $child     = $level + 3;
            $objects[] = sprintf('<< /Type /Pages /Kids [%1$d 0 R %1$d 0 R] /Count 2 >>', $child);
        }
        $objects[] = '<< /Type /Page /MediaBox [0 0 100 100] >>';

        return self::pdf($objects);
    }

    /**
     * SVG in which each group uses the previous one twice, so drawing it expands 2^$levels
     * references.
     */
    public static function useBomb(int $levels): string
    {
        $defs = '<rect id="a0" width="1" height="1"/>';
        for ($i = 1; $i <= $levels; ++$i) {
            $previous = $i - 1;
            $defs     .= "<g id=\"a{$i}\"><use href=\"#a{$previous}\"/><use href=\"#a{$previous}\"/></g>";
        }

        return (
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
                . "<defs>{$defs}</defs><use href=\"#a{$levels}\"/></svg>"
        );
    }

    /** A cross-reference stream with the given /W, declaring $size entries. */
    public static function xrefStream(string $widths, int $size): string
    {
        $catalog =
            "%PDF-1.7\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
        $offset = strlen($catalog);

        return (
            $catalog
                . "3 0 obj\n<< /Type /XRef /W {$widths} /Size {$size} /Root 1 0 R /Length 1 >>\nstream\n\0\nendstream\nendobj\n"
                . "startxref\n{$offset}\n%%EOF\n"
        );
    }
}
