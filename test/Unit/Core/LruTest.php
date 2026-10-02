<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use Php2Pdf\Core\Lru;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class LruTest extends TestCase
{
    #[Test]
    public function clearEmptiesTheCache(): void
    {
        $lru = new Lru(10);
        $lru->set('a', 1, 40);
        $lru->clear();

        self::assertSame([null, 0, 0], [$lru->get('a'), $lru->count(), $lru->weight()]);
    }

    #[Test]
    public function evictsTheLeastRecentlyUsedEntryOverTheCount(): void
    {
        $lru = new Lru(2);
        $lru->set('a', 1);
        $lru->set('b', 2);
        $lru->get('a');
        $lru->set('c', 3);

        self::assertSame([1, null, 3], [$lru->get('a'), $lru->get('b'), $lru->get('c')]);
    }

    #[Test]
    public function evictsTheOldestEntriesOverTheWeight(): void
    {
        $lru = new Lru(10, maxWeight: 100);
        $lru->set('a', 1, 40);
        $lru->set('b', 2, 40);
        $lru->set('c', 3, 40);

        self::assertSame([null, 2, 3, 80], [$lru->get('a'), $lru->get('b'), $lru->get('c'), $lru->weight()]);
    }

    #[Test]
    public function keepsTheNewestEntryEvenWhenItAloneIsOverweight(): void
    {
        $lru = new Lru(10, maxWeight: 100);
        $lru->set('a', 1, 40);
        $lru->set('big', 2, 500);

        self::assertSame([1, 2], [$lru->count(), $lru->get('big')]);
    }

    #[Test]
    public function replacingAnEntryReplacesItsWeight(): void
    {
        $lru = new Lru(10);
        $lru->set('a', 1, 40);
        $lru->set('a', 2, 10);

        self::assertSame([2, 1, 10], [$lru->get('a'), $lru->count(), $lru->weight()]);
    }

    #[Test]
    public function returnsNullForAMissingKey(): void
    {
        self::assertNull((new Lru(2))->get('a'));
    }
}
