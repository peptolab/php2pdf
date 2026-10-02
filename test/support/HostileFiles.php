<?php

declare(strict_types=1);

namespace Php2PdfTest\Support;

use function count;
use function gzcompress;
use function implode;
use function pack;
use function preg_replace;
use function sprintf;
use function str_repeat;
use function str_replace;
use function strlen;

/**
 * Small malformed or oversized files for robustness tests: decompression bombs, shared page
 * trees, bad cross-reference streams, deeply nested objects, oversized and inconsistent PNGs, and
 * deep, circular and self-expanding SVG.
 */
final class HostileFiles
{
    /** SVG whose two clip paths each clip the other. */
    public static function clipCycle(): string
    {
        return self::svg(
            '<clipPath id="a" clip-path="url(#b)"><rect width="5" height="5"/></clipPath>'
                . '<clipPath id="b" clip-path="url(#a)"><rect width="5" height="5"/></clipPath>',
            '<rect width="10" height="10" clip-path="url(#a)"/>',
        );
    }

    /**
     * A PDF with arrays nested $depth deep: as the page's /Annots object; in an object stream
     * holding it, which the reader only finds by repairing the cross-reference (its offset here
     * is wrong); or in the trailer, where the reader can only find the catalog by repairing.
     *
     * @param 'object'|'object stream'|'trailer' $where
     */
    public static function deepArrayPdf(int $depth, string $where): string
    {
        $deep    = str_repeat('[', $depth) . str_repeat(']', $depth);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Annots 4 0 R >>',
            'object' === $where ? $deep : '[]',
        ];
        if ('object stream' === $where) {
            $data       = "5 0 {$deep}";
            $objects[2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Annots 5 0 R >>';
            $objects[3] = sprintf(
                "<< /Type /ObjStm /N 1 /First 4 /Length %d >>\nstream\n%s\nendstream",
                strlen($data),
                $data,
            );
        }
        $pdf = self::pdf($objects);

        return match ($where) {
            'trailer'       => str_replace('/Root 1 0 R', "/Root 1 0 R /Deep {$deep}", $pdf),
            'object stream' => (string) preg_replace('/startxref\n\d+/', replacement: "startxref\n1", subject: $pdf),
            default         => $pdf,
        };
    }

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

    /** A baseline JPEG frame header declaring the given size, with no scan data after it. */
    public static function jpeg(int $width, int $height): string
    {
        return "\xFF\xD8\xFF\xC0" . pack('nCnnC', 11, 8, $height, $width, 1) . "\x01\x11\x00\xFF\xD9";
    }

    /**
     * SVG of $levels masks, each with two children masked by the next, so drawing it expands
     * 2^$levels masks without any of them referring to itself.
     */
    public static function maskBomb(int $levels): string
    {
        $defs = '';
        for ($i = 0; $i < $levels; ++$i) {
            $next = $i + 1;
            $defs .= "<mask id=\"m{$i}\"><rect width=\"5\" height=\"5\" fill=\"white\" mask=\"url(#m{$next})\"/>"
            . "<rect width=\"5\" height=\"5\" fill=\"white\" mask=\"url(#m{$next})\"/></mask>";
        }

        return self::svg($defs, '<rect width="10" height="10" mask="url(#m0)"/>');
    }

    /** SVG with a mask whose two children are each masked by the mask itself. */
    public static function maskFanOut(): string
    {
        return self::svg(
            '<mask id="m"><rect width="5" height="5" fill="white" mask="url(#m)"/>'
                . '<rect width="5" height="5" fill="white" mask="url(#m)"/></mask>',
            '<rect width="10" height="10" mask="url(#m)"/>',
        );
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

    /** A 10 by 10 SVG document of the given definitions and content. */
    public static function svg(string $defs, string $content): string
    {
        return (
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
                . "<defs>{$defs}</defs>{$content}</svg>"
        );
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

    /** SVG with a chain of $length groups, each drawing the next through <use>. */
    public static function useChain(int $length): string
    {
        $defs = '<rect id="a0" width="1" height="1"/>';
        for ($i = 1; $i <= $length; ++$i) {
            $previous = $i - 1;
            $defs     .= "<g id=\"a{$i}\"><use href=\"#a{$previous}\"/></g>";
        }

        return self::svg($defs, "<use href=\"#a{$length}\"/>");
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
