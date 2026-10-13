<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import\Security;

use Php2Pdf\Import\Security\Rc4;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;

#[Group('unit')]
final class Rc4Test extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> key, plaintext, ciphertext in hex */
    public static function vectorProvider(): array
    {
        return [
            'Key'    => ['Key', 'Plaintext', 'bbf316e8d940af0ad3'],
            'Wiki'   => ['Wiki', 'pedia', '1021bf0420'],
            'Secret' => ['Secret', 'Attack at dawn', '45a01f645fc35b383552544b9bf5'],
        ];
    }

    #[Test]
    public function decryptingIsEncryptingAgain(): void
    {
        self::assertSame('Attack at dawn', Rc4::crypt('Secret', Rc4::crypt('Secret', 'Attack at dawn')));
    }

    #[Test]
    #[DataProvider('vectorProvider')]
    public function encryptsAsThePublishedVectors(string $key, string $plain, string $cipher): void
    {
        self::assertSame($cipher, bin2hex(Rc4::crypt($key, $plain)));
    }
}
