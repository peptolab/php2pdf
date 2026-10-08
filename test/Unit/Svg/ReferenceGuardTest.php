<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Svg\ReferenceGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The guard every SVG reference is expanded through. */
#[Group('unit')]
final class ReferenceGuardTest extends TestCase
{
    #[Test]
    public function aReferenceBackToOneOfAnotherKindIsCircular(): void
    {
        $guard = new ReferenceGuard(10, 10);
        $guard->enter('clip-path', 'a');
        $guard->enter('mask', 'b');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The SVG refers to "a" from within itself: clip-path #a -> mask #b -> use #a.');
        $guard->enter('use', 'a');
    }

    #[Test]
    public function aReferenceCanBeExpandedAgainOnceLeft(): void
    {
        $guard = new ReferenceGuard(10, 10);
        $guard->within('use', 'a', static fn(): null => null);

        self::assertSame('drawn again', $guard->within('use', 'a', static fn(): string => 'drawn again'));
    }

    #[Test]
    public function aReferenceIsLeftWhenItsExpansionFails(): void
    {
        $guard = new ReferenceGuard(10, 10);
        try {
            $guard->within('mask', 'm', static function (): never {
                throw new ParseException('Broken mask.');
            });
        } catch (ParseException $e) {
            self::assertSame('Broken mask.', $e->getMessage());
        }

        self::assertSame('drawn', $guard->within('mask', 'm', static fn(): string => 'drawn'));
    }

    #[Test]
    public function elementsAndReferencesShareTheDepthLimit(): void
    {
        $guard = new ReferenceGuard(3, 10);
        $guard->descend();
        $guard->enter('use', 'a');
        $guard->descend();

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('nests elements and references more than 3 deep');
        $guard->enter('use', 'b');
    }

    #[Test]
    public function expandingPastTheWorkLimitIsRefused(): void
    {
        $guard = new ReferenceGuard(10, 2);
        $guard->within('use', 'a', static fn(): null => null);
        $guard->within('use', 'a', static fn(): null => null);

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The SVG expands more than 2 references.');
        $guard->enter('use', 'a');
    }

    #[Test]
    public function idsThatLookLikeNumbersAreTrackedAsWritten(): void
    {
        $guard = new ReferenceGuard(10, 10);
        $guard->enter('use', '1');
        $guard->enter('use', '01');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('use #1 -> use #01 -> mask #1');
        $guard->enter('mask', '1');
    }

    #[Test]
    public function leavingWithNothingEnteredDoesNotLowerTheDepth(): void
    {
        $guard = new ReferenceGuard(1, 10);
        $guard->leave();
        $guard->descend();

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('more than 1 deep');
        $guard->descend();
    }

    #[Test]
    public function theGuardTakesItsLimitsFromLimits(): void
    {
        $guard = ReferenceGuard::of(new Limits(maxSvgDepth: 1));
        $guard->descend();

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('more than 1 deep');
        $guard->descend();
    }
}
