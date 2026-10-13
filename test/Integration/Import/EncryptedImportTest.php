<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Import;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PermissionException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Import\PdfReader;
use Php2Pdf\Import\Security\StandardSecurity;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function file_get_contents;
use function is_array;
use function is_string;
use function preg_replace;
use function str_contains;
use function str_replace;

/**
 * Encrypted PDFs imported (#38): the fixtures are classic.pdf encrypted by qpdf 12 with each
 * revision of the standard security handler, without a user password unless their name says so
 * (the user password "user", the owner password "owner"); restricted.pdf forbids copying and
 * assembly.
 */
#[Group('integration')]
final class EncryptedImportTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> a change to the file, and the error it brings */
    public static function brokenProvider(): array
    {
        return [
            'another security handler'          => ['/Filter /Standard', '/Filter /Eternity', 'not supported'],
            'a later revision'                  => ['/R 6', '/R 7', 'not supported'],
            'an /Encrypt that is no dictionary' => ['/Encrypt 16 0 R', '/Encrypt 99 0 R', 'is not a dictionary'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function encryptedProvider(): array
    {
        return [
            'RC4, 40-bit (R2)'                  => ['rc4-40'],
            'RC4, 128-bit (R3)'                 => ['rc4-128'],
            'AES-128 (R4)'                      => ['aes-128'],
            'AES-128, metadata in clear (R4)'   => ['aes-128-cleartext-metadata'],
            'AES-256, Adobe extension (R5)'     => ['aes-256-r5'],
            'AES-256 (R6)'                      => ['aes-256'],
            'AES-256 (R6), with object streams' => ['aes-256-objstm'],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: ?string}> file, password, ignorePermissions, error */
    public static function passwordProvider(): array
    {
        return [
            'a user password needs it'               => ['user-password', '', false, 'This PDF needs a password'],
            'the user password opens it'             => ['user-password', 'user', false, null],
            'the owner password opens it'            => ['user-password', 'owner', false, null],
            'a wrong password does not'              => [
                'user-password',
                'nope',
                false,
                'The password does not open this PDF.',
            ],
            'an RC4 user password needs it'          => ['user-password-rc4', '', false, 'This PDF needs a password'],
            'the RC4 user password opens it'         => ['user-password-rc4', 'user', false, null],
            'the RC4 owner password opens it'        => ['user-password-rc4', 'owner', false, null],
            'a wrong RC4 password does not'          => [
                'user-password-rc4',
                'nope',
                false,
                'The password does not open this PDF.',
            ],
            'restrictions refuse an import'          => ['restricted', '', false, 'forbid copying its content'],
            'ignoring them opens it'                 => ['restricted', '', true, null],
            'the owner password lifts them'          => ['restricted', 'owner', false, null],
            'the owner password opens a 40-bit file' => ['rc4-40', 'owner', false, null],
        ];
    }

    /** A stream's data decoded by the reader, or nothing for anything else. */
    private static function decoded(PdfReader $reader, mixed $stream): string
    {
        return $stream instanceof Stream ? $reader->decode($stream) : '';
    }

    /** The first part of a file's /ID. */
    private static function first(mixed $id): string
    {
        return is_array($id) && is_string($id[0] ?? null) ? $id[0] : '';
    }

    private static function fixture(string $name): string
    {
        return PdfTools::assetPath("pdf/encrypted/{$name}.pdf");
    }

    /** The catalog's XMP metadata, as the reader reads it. */
    private static function metadata(string $name): string
    {
        $reader = PdfReader::fromFile(self::fixture($name));

        return self::decoded($reader, $reader->get($reader->catalog(), 'Metadata'));
    }

    #[Test]
    public function aDamagedEncryptedFileIsRepairedAndDecrypted(): void
    {
        $data = (string) preg_replace(
            '/startxref\s+\d+/',
            replacement: "startxref\n999999",
            subject: (string) file_get_contents(self::fixture('aes-256-objstm')),
        );

        self::assertSame('Import corpus: classic xref', PdfFile::fromData($data)->info()['Title'] ?? null);
    }

    #[Test]
    #[DataProvider('encryptedProvider')]
    public function anEncryptedFileImportsAsItsPlainOriginal(string $name): void
    {
        $file = PdfFile::open(self::fixture($name));
        $path = PdfTools::outputDir() . "/encrypted-{$name}.pdf";
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(PageSize::A4)->place($doc->import($file)->page(1), new Box(0.0, 0.0, 595.0, 842.0), Fit::meet());
        $doc->close();
        PdfTools::assertValid($path);

        self::assertSame(
            ['Import corpus: classic xref', true],
            [$file->info()['Title'] ?? null, str_contains(PdfTools::text($path), 'Page 1 of the import corpus')],
        );
    }

    #[Test]
    #[DataProvider('brokenProvider')]
    public function anEncryptionTheLibraryDoesNotKnowIsRefused(string $from, string $to, string $error): void
    {
        $data = (string) file_get_contents(self::fixture('aes-256'));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($error);
        PdfFile::fromData(str_replace($from, $to, $data));
    }

    #[Test]
    public function metadataLeftInClearIsReadAsItIs(): void
    {
        $clear     = self::metadata('aes-128-cleartext-metadata');
        $encrypted = self::metadata('aes-128');

        self::assertSame([true, true], [str_contains($clear, '<x:xmpmeta'), str_contains($encrypted, '<x:xmpmeta')]);
    }

    #[Test]
    #[DataProvider('passwordProvider')]
    public function passwordsAndPermissionsDecideWhetherAFileOpens(
        string $name,
        #[SensitiveParameter]
        string $password,
        bool $ignorePermissions,
        ?string $error,
    ): void {
        if (null !== $error) {
            $this->expectException(PermissionException::class);
            $this->expectExceptionMessage($error);
        }

        self::assertSame(
            2,
            PdfFile::open(
                self::fixture($name),
                password: $password,
                ignorePermissions: $ignorePermissions,
            )->pageCount(),
        );
    }

    #[Test]
    public function streamsAnIdentityCryptFilterNamesStayInClear(): void
    {
        $reader  = PdfReader::fromFile(self::fixture('aes-128'));
        $encrypt = $reader->dict($reader->trailer(), 'Encrypt');
        self::assertNotNull($encrypt);
        $identity = new Dictionary($encrypt->all());
        $identity->set('StmF', new Name('Identity'));
        $security = StandardSecurity::open($identity, self::first($reader->trailer()->get('ID')), '');

        self::assertSame('as it is', $security->object(Stream::of('as it is'), 4, 0)->data ?? null);
    }
}
