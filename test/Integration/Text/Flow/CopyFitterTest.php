<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Override;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[Group('integration')]
final class CopyFitterTest extends TestCase
{
    private Document $doc;

    /** @return array<string, array{0: string}> */
    public static function breakProvider(): array
    {
        return [
            'soft hyphens' => [str_repeat("hy\u{00AD}phen\u{00AD}ation ", times: 30)],
            'hard hyphens' => [str_repeat('self-made well-known ', times: 20)],
            'line breaks'  => [str_repeat("word\n", times: 20)],
        ];
    }

    /** Break points that show nothing, or a hyphen, are re-made at the shrunk size. */
    #[Test]
    #[DataProvider('breakProvider')]
    public function textWithBreakPointsShrinksToFit(string $text): void
    {
        $flow = $this->doc->textflow($text, 'body');
        $box  = new Box(0.0, 0.0, 120.0, 80.0);

        self::assertSame(
            [FlowResult::Done, true],
            [
                $this->doc->addPage()->fitTextflow($flow, $box, FlowOptions::shrinkToFit(0.2)),
                ($flow->lastFit()->scale ?? 1.0) < 1.0,
            ],
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->doc = new Document(
            new MemoryOutput(),
            new DocumentOptions(
                clock: new FixedClock('2026-01-01'),
                compress: false,
                xmp: false,
            ),
        );
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
        $this->doc->styles()->define('body', new TextStyle(
            font: 'DejaVuSans',
            size: 10.0,
            leading: Leading::pt(12.0),
        ));
    }
}
