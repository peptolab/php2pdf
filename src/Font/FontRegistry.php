<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Php2Pdf\Core\Lru;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\ExceptionInterface;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\OpenType\OpenTypeFont;

use function filemtime;
use function glob;
use function in_array;
use function is_dir;
use function is_file;
use function pathinfo;
use function preg_replace;
use function realpath;
use function sort;
use function spl_object_id;
use function sprintf;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const GLOB_BRACE;
use const PATHINFO_FILENAME;

/**
 * Loads fonts for a document by path or by name from search paths, and groups them into families.
 * Each font file is embedded once per document however often it is requested. The standard 14
 * fonts (Helvetica, Times-Roman, Courier...) are found by name when no font file matches.
 *
 * @api
 */
final class FontRegistry
{
    /** Directories whose faces are kept between documents. */
    private const int INDEX_ENTRIES = 64;

    /** @var Lru<array{0: int, 1: list<FontInfo>}>|null directory => modification time and faces */
    private static ?Lru $index = null;

    /** @var list<string> */
    private array $searchPaths = [];

    /** @var array<string, Font> path#face => embedded font */
    private array $fonts = [];

    /** @var array<string, CoreFont> */
    private array $standard = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @internal */
    public function __construct(
        private readonly Objects $objects,
        private readonly FontOptions $options = new FontOptions(),
    ) {}

    /**
     * Empties the index of search-path directories shared between documents; a directory is also
     * re-read when its modification time changes, as it does when fonts are added or removed.
     */
    public static function clearCache(): void
    {
        self::$index?->clear();
    }

    private static function canonical(string $path): string
    {
        $real = realpath($path);

        return false === $real ? $path : $real;
    }

    /** @return Lru<array{0: int, 1: list<FontInfo>}> */
    private static function index(): Lru
    {
        return self::$index ??= new Lru(self::INDEX_ENTRIES);
    }

    /** @return list<FontInfo> */
    private static function indexDirectory(string $directory): array
    {
        $index    = self::index();
        $modified = (int) filemtime($directory);
        $cached   = $index->get($directory);
        if (null !== $cached && $cached[0] === $modified) {
            return $cached[1];
        }
        $faces = [];
        $files = glob("{$directory}/*.{ttf,otf,ttc,TTF,OTF,TTC}", flags: GLOB_BRACE);
        $files = false === $files ? [] : $files;
        sort($files);
        foreach ($files as $file) {
            try {
                foreach (FontInfo::peek($file) as $face) {
                    $faces[] = $face;
                }
            } catch (ExceptionInterface) {
                // Unreadable or malformed fonts are skipped rather than failing the whole index.
                continue;
            }
        }

        $index->set($directory, [$modified, $faces]);

        return $faces;
    }

    private static function normalise(string $name): string
    {
        return strtolower(preg_replace('/[\s_-]+/', replacement: '', subject: $name) ?? $name);
    }

    /** @throws IoException */
    public function addSearchPath(string $directory): self
    {
        $real = realpath($directory);
        if (false === $real || ! is_dir($real)) {
            throw new IoException(sprintf('Font search path "%s" is not a directory.', $directory));
        }
        if (! in_array($real, $this->searchPaths, strict: true)) {
            $this->searchPaths[] = $real;
        }

        return $this;
    }

    /**
     * Embeds an already parsed font program.
     *
     * @throws ParseException
     */
    public function embed(OpenTypeFont $program): Font
    {
        return $this->fonts['program#' . spl_object_id($program)] ??= new Font(
            $this->objects,
            $this,
            $this->options,
            $program,
        );
    }

    /**
     * Every face found in the search paths.
     *
     * @return list<FontInfo>
     */
    public function faces(): array
    {
        $faces = [];
        foreach ($this->searchPaths as $directory) {
            foreach (self::indexDirectory($directory) as $face) {
                $faces[] = $face;
            }
        }

        return $faces;
    }

    /** The faces of a family, matched case-insensitively on the family name. */
    public function family(string $family): FontFamily
    {
        $wanted = self::normalise($family);
        $faces  = [];
        foreach ($this->faces() as $face) {
            if (self::normalise($face->family) !== $wanted) {
                continue;
            }
            $faces[] = $face;
        }
        if ([] === $faces) {
            throw new InvalidArgumentException(sprintf('Font family "%s" not found in the search paths.', $family));
        }

        return new FontFamily($this, $faces[0]->family, $faces);
    }

    /**
     * Finds a font by PostScript name, full name, "Family Style", or file name, case-insensitively.
     * A font file in the search paths wins over a standard font of the same name.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function find(string $name): FontInterface
    {
        $info = $this->lookup($name);
        if (null !== $info) {
            return $this->load($info->path, $info->faceIndex);
        }
        $standard = StandardFont::fromName($name) ?? throw new InvalidArgumentException(sprintf(
            'Font "%s" not found in the search paths, and it is not one of the standard 14 fonts.',
            $name,
        ));

        return $this->standard($standard);
    }

    public function has(string $name): bool
    {
        return null !== $this->lookup($name) || null !== StandardFont::fromName($name);
    }

    /**
     * Embeds a font file. Relative paths are resolved against the search paths.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function load(string $path, int $faceIndex = 0): Font
    {
        $resolved = $this->resolvePath($path);

        return $this->fonts["{$resolved}#{$faceIndex}"] ??= new Font(
            $this->objects,
            $this,
            $this->options,
            OpenTypeFont::fromFile($resolved, $faceIndex),
        );
    }

    /** @return list<string> */
    public function searchPaths(): array
    {
        return $this->searchPaths;
    }

    /**
     * Uses one of the standard 14 fonts: not embedded, so it cannot be used under PDF/X.
     *
     * @throws PdfException when the document is written to a PDF/X level
     */
    public function standard(StandardFont $font): CoreFont
    {
        return $this->standard[$font->value] ??= new CoreFont($this->objects, $this->options, $font);
    }

    /**
     * Records a font the document could only embed in a degraded way.
     *
     * @internal
     */
    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * Fonts the document could only embed in a degraded way, such as a CFF font embedded whole
     * because it could not be subset. Complete once the document is closed.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function lookup(string $name): ?FontInfo
    {
        $wanted = self::normalise($name);
        $stem   = self::normalise(pathinfo($name, flags: PATHINFO_FILENAME));
        foreach ($this->faces() as $face) {
            $candidates = [
                $face->postScriptName,
                $face->fullName,
                "{$face->family} {$face->subfamily}",
                pathinfo($face->path, flags: PATHINFO_FILENAME),
            ];
            foreach ($candidates as $candidate) {
                $normalised = self::normalise($candidate);
                if ($normalised === $wanted || $normalised === $stem) {
                    return $face;
                }
            }
        }

        return null;
    }

    /** @throws IoException */
    private function resolvePath(string $path): string
    {
        if (is_file($path)) {
            return self::canonical($path);
        }
        foreach ($this->searchPaths as $directory) {
            foreach ([$path, "{$path}.ttf", "{$path}.otf", "{$path}.ttc"] as $candidate) {
                $full = $directory . DIRECTORY_SEPARATOR . $candidate;
                if (is_file($full)) {
                    return self::canonical($full);
                }
            }
        }
        throw new IoException(sprintf('Font file "%s" not found.', $path));
    }
}
