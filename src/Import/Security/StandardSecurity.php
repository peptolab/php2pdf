<?php

declare(strict_types=1);

namespace Php2Pdf\Import\Security;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PermissionException;
use SensitiveParameter;

use function chr;
use function hash;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function md5;
use function min;
use function ord;
use function pack;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;

/**
 * The standard security handler of an encrypted PDF (ISO 32000-2, 7.6.4): the file key worked out
 * from a password for revisions 2 to 6, and strings and streams decrypted with the key for their
 * object, by RC4 or AES. An empty password opens files that have no user password; the owner
 * password opens any file and lifts its permissions.
 *
 * @internal
 */
final readonly class StandardSecurity
{
    /** The padding a revision 2 to 4 password is completed with to 32 bytes. */
    private const string PAD =
        "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
            . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    /** Permission bit (from 1) 5: copying text and graphics. */
    private const int COPY = 1 << 4;

    /** Permission bit 11: assembling pages, from revision 3. */
    private const int ASSEMBLE = 1 << 10;

    /** Permission bit 4: modifying the document, which governs assembling pages in revision 2. */
    private const int MODIFY = 1 << 3;

    private const string LOCKED_MESSAGE = 'This PDF needs a password: open it with PdfFile::open($path, password: ...).';

    private const string REFUSED_MESSAGE = 'The password does not open this PDF.';

    private function __construct(
        private string $key,
        private int $revision,
        private int $permissions,
        private bool $owner,
        /** How strings are encrypted: None, V2 (RC4), AESV2 or AESV3. */
        private string $strings,
        /** How streams are encrypted, as strings are. */
        private string $streams,
        private bool $metadata,
    ) {}

    /**
     * The handler for an /Encrypt dictionary, opened with a password (empty for files without a
     * user password) and the first part of the file's /ID.
     *
     * @throws ParseException when the handler is not the standard one
     * @throws PermissionException when the password does not open the file
     */
    public static function open(
        Dictionary $encrypt,
        string $fileId,
        #[SensitiveParameter]
        string $password,
    ): self {
        $filter = $encrypt->get('Filter');
        if (! $filter instanceof Name || 'Standard' !== $filter->value) {
            throw new ParseException(sprintf(
                'PDF files encrypted with the %s security handler are not supported.',
                $filter instanceof Name ? $filter->value : 'unnamed',
            ));
        }
        $revision = self::int($encrypt, 'R');
        if ($revision < 2 || $revision > 6) {
            throw new ParseException(sprintf(
                'Revision %d of the standard security handler is not supported.',
                $revision,
            ));
        }
        $metadata = false !== $encrypt->get('EncryptMetadata');
        [$strings, $streams] = self::methods($encrypt, self::int($encrypt, 'V'));
        [$key, $owner] = $revision >= 5
            ? self::modernKey($encrypt, $revision, $password)
            : self::legacyKey($encrypt, $fileId . ($revision >= 4 && ! $metadata ? "\xFF\xFF\xFF\xFF" : ''), $password);

        return new self($key, $revision, self::int($encrypt, 'P'), $owner, $strings, $streams, $metadata);
    }

    private static function bytes(Dictionary $dict, string $key): string
    {
        $value = $dict->get($key);

        return is_string($value) ? $value : '';
    }

    /**
     * The hash of revisions 5 and 6 (Algorithms 2.A and 2.B): SHA-256 for revision 5; for revision
     * 6, rounds of AES-128 and SHA-2, at least 64, until the last byte of a round lets them end.
     *
     * @throws ParseException
     */
    private static function hash(
        int $revision,
        #[SensitiveParameter]
        string $password,
        string $salt,
        string $userKey,
    ): string {
        $k = hash('sha256', $password . $salt . $userKey, binary: true);
        if (5 === $revision) {
            return $k;
        }
        $round = 0;
        do {
            $e = Aes::encryptBlocks(
                substr($k, offset: 0, length: 16),
                substr($k, offset: 16, length: 16),
                str_repeat($password . $k . $userKey, times: 64),
            );
            $sum = 0;
            for ($i = 0; $i < 16; ++$i) {
                $sum += ord($e[$i] ?? "\0");
            }
            $k = hash(
                match ($sum % 3) {
                    0       => 'sha256',
                    1       => 'sha384',
                    default => 'sha512',
                },
                $e,
                binary: true,
            );
            ++$round;
        } while ($round < 64 || ord($e[strlen($e) - 1] ?? "\0") > ($round - 32));

        return substr($k, offset: 0, length: 32);
    }

    private static function int(Dictionary $dict, string $key): int
    {
        $value = $dict->get($key);

        return is_int($value) ? $value : 0;
    }

    /**
     * The file key for revisions 2 to 4 (Algorithm 2), from the user password, or from the owner
     * password by decrypting the user password from /O (Algorithm 7); and whether it was the
     * owner's. $salt is the file's /ID, followed for revision 4 files that leave metadata in clear
     * by four 0xFF bytes.
     *
     * @return array{0: string, 1: bool}
     *
     * @throws ParseException
     * @throws PermissionException when neither password opens the file
     */
    private static function legacyKey(
        Dictionary $encrypt,
        string $salt,
        #[SensitiveParameter]
        string $password,
    ): array {
        $revision = self::int($encrypt, 'R');
        $o        = self::bytes($encrypt, 'O');
        $bits     = self::int($encrypt, 'Length');
        $length   = 2 === $revision ? 5 : intdiv(0 === $bits ? 40 : $bits, num2: 8);
        $prefix   = $o . pack('V', self::int($encrypt, 'P') & 0xFFFF_FFFF) . $salt;
        $fileKey  = self::passwordKey($password, $prefix, $revision, $length);
        if (self::opens($encrypt, $fileKey, $salt)) {
            return [$fileKey, false];
        }
        $fileKey = self::passwordKey(self::ownerUser($o, $password, $revision, $length), $prefix, $revision, $length);
        if (self::opens($encrypt, $fileKey, $salt)) {
            return [$fileKey, true];
        }
        throw new PermissionException('' === $password ? self::LOCKED_MESSAGE : self::REFUSED_MESSAGE);
    }

    /**
     * How strings and streams are encrypted: by RC4 before version 4, otherwise as the crypt
     * filters /StrF and /StmF name have it, Identity leaving them in clear.
     *
     * @return array{0: string, 1: string}
     */
    private static function methods(Dictionary $encrypt, int $version): array
    {
        if ($version < 4) {
            return ['V2', 'V2'];
        }
        $filters = $encrypt->get('CF');
        $method  = static function (string $key) use ($encrypt, $filters): string {
            $name = $encrypt->get($key);
            $cf   = $filters instanceof Dictionary && $name instanceof Name ? $filters->get($name->value) : null;
            $cfm  = $cf instanceof Dictionary ? $cf->get('CFM') : null;

            return $cfm instanceof Name ? $cfm->value : 'None';
        };

        return [$method('StrF'), $method('StmF')];
    }

    /**
     * The file key for revisions 5 and 6, unwrapped from /OE with the owner password or from /UE
     * with the user password; and whether it was the owner's.
     *
     * @return array{0: string, 1: bool}
     *
     * @throws ParseException
     * @throws PermissionException when neither password opens the file
     */
    private static function modernKey(
        Dictionary $encrypt,
        int $revision,
        #[SensitiveParameter]
        string $password,
    ): array {
        $password = substr($password, offset: 0, length: 127);
        $u        = self::bytes($encrypt, 'U');
        $o        = self::bytes($encrypt, 'O');
        $user     = substr($u, offset: 0, length: 48);
        $zero     = str_repeat("\0", times: 16);
        $owner    = self::hash($revision, $password, substr($o, offset: 32, length: 8), $user);
        if (substr($o, offset: 0, length: 32) === $owner) {
            $key = self::hash($revision, $password, substr($o, offset: 40, length: 8), $user);

            return [Aes::decryptBlocks($key, $zero, self::bytes($encrypt, 'OE')), true];
        }
        if (
            self::hash($revision, $password, substr($u, offset: 32, length: 8), '') === substr(
                $u,
                offset: 0,
                length: 32,
            )
        ) {
            $key = self::hash($revision, $password, substr($u, offset: 40, length: 8), '');

            return [Aes::decryptBlocks($key, $zero, self::bytes($encrypt, 'UE')), false];
        }
        throw new PermissionException('' === $password ? self::LOCKED_MESSAGE : self::REFUSED_MESSAGE);
    }

    /**
     * Whether a file key is the file's: /U is what it encrypts the padding to (Algorithms 4 and 5).
     */
    private static function opens(Dictionary $encrypt, string $key, string $salt): bool
    {
        $u = self::bytes($encrypt, 'U');
        if (2 === self::int($encrypt, 'R')) {
            return Rc4::crypt($key, self::PAD) === $u;
        }
        $x = Rc4::crypt($key, md5(self::PAD . substr($salt, offset: 0, length: 16), binary: true));
        for ($i = 1; $i <= 19; ++$i) {
            $x = Rc4::crypt(self::xorKey($key, $i), $x);
        }

        return substr($u, offset: 0, length: 16) === $x;
    }

    /**
     * The user password the owner password decrypts from /O, for revisions 2 to 4 (Algorithm 7).
     */
    private static function ownerUser(
        string $o,
        #[SensitiveParameter]
        string $password,
        int $revision,
        int $length,
    ): string {
        $hash = md5(substr($password . self::PAD, offset: 0, length: 32), binary: true);
        for ($i = 0; $revision >= 3 && $i < 50; ++$i) {
            $hash = md5($hash, binary: true);
        }
        $key = substr($hash, offset: 0, length: $length);
        if (2 === $revision) {
            return Rc4::crypt($key, $o);
        }
        $user = $o;
        for ($i = 19; $i >= 0; --$i) {
            $user = Rc4::crypt(self::xorKey($key, $i), $user);
        }

        return $user;
    }

    /**
     * A user password's file key (Algorithm 2): the padded password hashed with /O, /P and the salt,
     * and for revision 3 and later hashed 50 times more.
     */
    private static function passwordKey(
        #[SensitiveParameter]
        string $password,
        string $prefix,
        int $revision,
        int $length,
    ): string {
        $hash = md5(substr($password . self::PAD, offset: 0, length: 32) . $prefix, binary: true);
        for ($i = 0; $revision >= 3 && $i < 50; ++$i) {
            $hash = md5(substr($hash, offset: 0, length: $length), binary: true);
        }

        return substr($hash, offset: 0, length: $length);
    }

    /** The key with every byte XORed with $n, as revision 3's twenty RC4 rounds use it. */
    private static function xorKey(string $key, int $n): string
    {
        $out = '';
        for ($i = 0, $len = strlen($key); $i < $len; ++$i) {
            $out .= chr(ord($key[$i]) ^ $n);
        }

        return $out;
    }

    /** Whether the file's permissions forbid copying its content or assembling its pages elsewhere. */
    public function forbidsImport(): bool
    {
        if ($this->owner) {
            return false;
        }
        $assemble = $this->revision >= 3 ? self::ASSEMBLE : self::MODIFY;

        return 0 === ($this->permissions & self::COPY) || 0 === ($this->permissions & $assemble);
    }

    /**
     * An object decrypted for its number and generation: its strings, and its stream's data
     * unless the stream is a cross-reference stream or metadata the file leaves in clear.
     *
     * @throws ParseException
     */
    public function object(mixed $value, int $number, int $generation): mixed
    {
        if (is_string($value)) {
            return $this->decrypt($this->strings, $value, $number, $generation);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->object($item, $number, $generation);
            }

            return $out;
        }
        if ($value instanceof Dictionary) {
            return $this->dictionary($value, $number, $generation);
        }
        if (! $value instanceof Stream) {
            return $value;
        }
        $type = $value->dictionary->get('Type');
        $type = $type instanceof Name ? $type->value : '';
        if ('XRef' === $type) {
            return $value;
        }
        $data = 'Metadata' === $type && ! $this->metadata
            ? $value->data
            : $this->decrypt($this->streams, $value->data, $number, $generation);

        return new Stream($this->dictionary($value->dictionary, $number, $generation), $data, $value->compress);
    }

    /**
     * Data decrypted by a method: with the key for its object (Algorithm 1) for RC4 and AES-128,
     * the file key for AES-256, and left as it is for None and Identity.
     *
     * @throws ParseException
     */
    private function decrypt(string $method, string $data, int $number, int $generation): string
    {
        if ('AESV3' === $method) {
            return Aes::decrypt($this->key, $data);
        }
        if ('V2' !== $method && 'AESV2' !== $method) {
            return $data;
        }
        $key = substr(
            md5(
                $this->key
                    . substr(pack('V', $number), offset: 0, length: 3)
                    . substr(pack('V', $generation), offset: 0, length: 2)
                    . ('AESV2' === $method ? 'sAlT' : ''),
                binary: true,
            ),
            offset: 0,
            length: min(strlen($this->key) + 5, 16),
        );

        return 'AESV2' === $method ? Aes::decrypt($key, $data) : Rc4::crypt($key, $data);
    }

    /**
     * A dictionary with its strings decrypted.
     *
     * @throws ParseException
     */
    private function dictionary(Dictionary $dictionary, int $number, int $generation): Dictionary
    {
        $out = new Dictionary();
        foreach ($dictionary->all() as $key => $item) {
            $out->set($key, $this->object($item, $number, $generation));
        }

        return $out;
    }
}
