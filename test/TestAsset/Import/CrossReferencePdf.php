<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Import;

use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\LayeredPdf;

use function pack;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strlen;

/**
 * One-page PDFs with unusual cross-reference structures: cross-reference streams, object streams
 * with broken headers, offsets past the end, hybrid files, and document information. Objects 1 to 3 are always
 * the catalog, the page tree and the page.
 */
final class CrossReferencePdf
{
    private const array PAGE = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>',
    ];

    /**
     * A file whose cross-reference stream (object 5) places objects 6 and 7 in object stream 4,
     * whose header lists object 6, the string "six", and a stale copy of object 3, the string
     * "stale", which the cross-reference places in the file, and then breaks off before object 7.
     */
    public static function brokenObjectStreamHeader(): string
    {
        [$pdf, $offsets] = self::objects([
            ...self::PAGE,
            LayeredPdf::stream('6 0 3 6 /x (six) (stale)', '/Type /ObjStm /N 3 /First 11'),
        ]);
        $xref = strlen($pdf);
        $rows = [[0, 0, 65_535]];
        foreach ($offsets as $offset) {
            $rows[] = [1, $offset, 0];
        }
        $rows[] = [1, $xref, 0];
        $rows[] = [2, 4, 0];
        $rows[] = [2, 4, 1];

        return $pdf . self::xrefStream(5, $rows, '/Size 8 /Root 1 0 R') . "startxref\n{$xref}\n%%EOF\n";
    }

    /**
     * A hybrid file: its cross-reference table lists objects 0 to 3 and names, with /XRefStm, a
     * cross-reference stream that alone lists object 4, the string "first". A second object 4,
     * "second", follows later in the file, where only a repairing scan would pick it.
     */
    public static function hybrid(): string
    {
        [$pdf, $offsets] = self::objects([...self::PAGE, '(first)']);
        $stream = strlen($pdf);
        $pdf    .= self::xrefStream(5, [[1, $offsets[4] ?? 0, 0]], '/Size 6 /Index [4 1]');
        $pdf    .= "4 0 obj\n(second)\nendobj\n";
        $table  = strlen($pdf);
        $pdf    .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ([1, 2, 3] as $number) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }

        return $pdf . "trailer\n<< /Size 6 /Root 1 0 R /XRefStm {$stream} >>\nstartxref\n{$table}\n%%EOF\n";
    }

    /** A file whose cross-reference table puts the page (object 3) past the end of the file. */
    public static function offsetPastTheEnd(): string
    {
        return (string) preg_replace(
            '/\d{10} 00000 n \ntrailer/',
            replacement: "9999999999 00000 n \ntrailer",
            subject: HostileFiles::pages(1),
        );
    }

    /** A file whose document information (object 4) has the given title bytes, which must not need escaping. */
    public static function titled(string $title): string
    {
        return str_replace(
            search: '/Root 1 0 R',
            replace: '/Root 1 0 R /Info 4 0 R',
            subject: HostileFiles::pdf([...self::PAGE, "<< /Title ({$title}) >>"]),
        );
    }

    /**
     * The header and the given objects, numbered from 1, with each object's offset.
     *
     * @param list<string> $objects
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private static function objects(array $objects): array
    {
        $pdf     = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf             .= sprintf("%d 0 obj\n%s\nendobj\n", $i + 1, $body);
        }

        return [$pdf, $offsets];
    }

    /**
     * A cross-reference stream object with /W [1 4 2].
     *
     * @param list<array{0: int, 1: int, 2: int}> $rows type, offset or stream number, generation or index
     */
    private static function xrefStream(int $number, array $rows, string $entries): string
    {
        $data = '';
        foreach ($rows as $row) {
            $data .= pack('CNn', $row[0], $row[1], $row[2]);
        }

        return sprintf(
            "%d 0 obj\n%s\nendobj\n",
            $number,
            LayeredPdf::stream($data, "/Type /XRef /W [1 4 2] {$entries}"),
        );
    }
}
