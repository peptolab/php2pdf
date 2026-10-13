<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Accessibility;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PdfA;

use function preg_match_all;

use const PREG_SET_ORDER;

/** Tagged documents written uncompressed to memory, and what their output holds (#35). */
final class TaggedDocument
{
    /** How many structure elements of a role the output holds. */
    public static function count(string $pdf, string $role): int
    {
        return (int) preg_match_all("#/Type/StructElem/S/{$role}/#", $pdf);
    }

    /**
     * The marked-content operators of the output's content streams, in order: "/P 0" for an
     * element's sequence, "Artifact" for an artifact's and "EMC" for an end.
     *
     * @return list<string>
     */
    public static function marks(string $pdf): array
    {
        $matches = [];
        preg_match_all('#^(?:/(\w+) <</MCID (\d+)>> BDC|/(Artifact) BMC|(EMC))$#m', $pdf, $matches, PREG_SET_ORDER);
        $marks = [];
        foreach ($matches as $match) {
            $marks[] = match (true) {
                '' !== ($match[4] ?? '') => 'EMC',
                '' !== ($match[3] ?? '') => 'Artifact',
                default => '/' . ($match[1] ?? '') . ' ' . ($match[2] ?? ''),
            };
        }

        return $marks;
    }

    /**
     * A document tagged as asked, with a title, written uncompressed and without object streams.
     *
     * @return array{0: Document, 1: MemoryOutput}
     */
    public static function open(?Accessibility $accessibility = new Accessibility('en-GB'), ?PdfA $pdfa = null): array
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T12:00:00Z'),
            objectStreams: false,
            pdfa: $pdfa,
            accessibility: $accessibility,
        ));
        $doc->info()->setTitle('Tagged');

        return [$doc, $out];
    }
}
