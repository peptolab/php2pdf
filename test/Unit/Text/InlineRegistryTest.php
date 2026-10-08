<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text;

use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Text\InlineRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class InlineRegistryTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function nameProvider(): array
    {
        return [
            'a defined name'  => ['logo', true],
            'an unknown name' => ['icon', false],
        ];
    }

    #[Test]
    #[DataProvider('nameProvider')]
    public function knowsWhichNamesAreDefined(string $name, bool $defined): void
    {
        $registry = (new InlineRegistry())->define('logo', $this->createStub(PlaceableInterface::class));

        self::assertSame($defined, $registry->has($name));
    }
}
