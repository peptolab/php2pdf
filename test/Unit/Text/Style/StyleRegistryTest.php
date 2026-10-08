<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Style;

use Php2Pdf\Text\Style\StyleRegistry;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class StyleRegistryTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function nameProvider(): array
    {
        return [
            'a defined style'  => ['body', true],
            'an unknown style' => ['h1', false],
        ];
    }

    #[Test]
    #[DataProvider('nameProvider')]
    public function knowsWhichStylesAreDefined(string $name, bool $defined): void
    {
        $registry = (new StyleRegistry())->define('body', new TextStyle(size: 10.0));

        self::assertSame($defined, $registry->has($name));
    }

    #[Test]
    public function listsTheNamesInTheOrderTheyWereDefined(): void
    {
        $registry = (new StyleRegistry())->define('body', new TextStyle(size: 10.0))
            ->define('h1', new TextStyle(size: 20.0), basedOn: 'body');

        self::assertSame(['body', 'h1'], $registry->names());
    }
}
