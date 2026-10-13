<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import\Security;

use Php2Pdf\Import\Security\Aes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/** AES-CBC as PDF strings and streams carry it: an IV, then whole blocks padded PKCS#7. */
#[Group('unit')]
final class AesTest extends TestCase
{
    private const string IV = '0123456789abcdef';

    /** @return array<string, array{0: string}> */
    public static function keyProvider(): array
    {
        return ['AES-128' => [str_repeat('k', times: 16)], 'AES-256' => [str_repeat('k', times: 32)]];
    }

    /** @return array<string, array{0: string, 1: string}> the padded plaintext, and what it decrypts to */
    public static function paddingProvider(): array
    {
        return [
            'padding removed'                  => ["hello\x0B\x0B\x0B\x0B\x0B\x0B\x0B\x0B\x0B\x0B\x0B", 'hello'],
            'a whole block of padding removed' => [
                '0123456789abcdef' . str_repeat("\x10", times: 16),
                '0123456789abcdef',
            ],
            'padding that is wrong, kept'      => [
                "hello world\x05\x05\x05\x05\x04",
                "hello world\x05\x05\x05\x05\x04",
            ],
        ];
    }

    #[Test]
    public function blocksAreLeftAsTheyAreWithoutPadding(): void
    {
        $key = str_repeat('k', times: 32);

        self::assertSame(
            '0123456789abcdef',
            Aes::decryptBlocks($key, self::IV, Aes::encryptBlocks($key, self::IV, '0123456789abcdef')),
        );
    }

    /** @param string $key */
    #[Test]
    #[DataProvider('keyProvider')]
    public function dataDecryptsWithTheIvBeforeIt(string $key): void
    {
        $padded = "secret text\x05\x05\x05\x05\x05";

        self::assertSame('secret text', Aes::decrypt($key, self::IV . Aes::encryptBlocks($key, self::IV, $padded)));
    }

    #[Test]
    public function dataTooShortForABlockAfterItsIvIsEmpty(): void
    {
        self::assertSame('', Aes::decrypt(str_repeat('k', times: 16), self::IV . 'short'));
    }

    #[Test]
    #[DataProvider('paddingProvider')]
    public function paddingIsRemovedWhenItIsPadding(string $padded, string $plain): void
    {
        $key = str_repeat('k', times: 16);

        self::assertSame($plain, Aes::decrypt($key, self::IV . Aes::encryptBlocks($key, self::IV, $padded)));
    }

    #[Test]
    public function partBlocksDecryptToNothing(): void
    {
        self::assertSame('', Aes::decryptBlocks(str_repeat('k', times: 16), self::IV, 'five!'));
    }
}
