<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use PhpPdf\Document\Document;
use PhpPdf\Exception\IoException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Font\OpenType\OpenTypeFont;

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
 * Each font file is embedded once per document however often it is requested.
 *
 * @api
 */
final class FontRegistry
{
    /** @var array<string, list<FontInfo>> directory => faces, shared across documents */
    private static array $index = [];

    /** @var list<string> */
    private array $searchPaths = [];

    /** @var array<string, Font> path#face => embedded font */
    private array $fonts = [];

    /** @internal */
    public function __construct(
        private readonly Document $document,
    ) {}

    private static function canonical(string $path): string
    {
        $real = realpath($path);

        return false === $real ? $path : $real;
    }

    /** @return list<FontInfo> */
    private static function indexDirectory(string $directory): array
    {
        $cached = self::$index[$directory] ?? null;
        if (null !== $cached) {
            return $cached;
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
            } catch (PdfException) {
                // Unreadable or malformed fonts are skipped rather than failing the whole index.
                continue;
            }
        }

        return self::$index[$directory] = $faces;
    }

    private static function normalise(string $name): string
    {
        return strtolower(preg_replace('/[\s_-]+/', replacement: '', subject: $name) ?? $name);
    }

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

    /** Embeds an already parsed font program. */
    public function embed(OpenTypeFont $program): Font
    {
        return $this->fonts['program#' . spl_object_id($program)] ??= new Font($this->document, $program);
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
            throw new PdfException(sprintf('Font family "%s" not found in the search paths.', $family));
        }

        return new FontFamily($this, $faces[0]->family, $faces);
    }

    /**
     * Finds a font by PostScript name, full name, "Family Style", or file name, case-insensitively.
     */
    public function find(string $name): Font
    {
        $info = $this->lookup($name) ?? throw new PdfException(sprintf(
            'Font "%s" not found in the search paths.',
            $name,
        ));

        return $this->load($info->path, $info->faceIndex);
    }

    public function has(string $name): bool
    {
        return null !== $this->lookup($name);
    }

    /**
     * Embeds a font file. Relative paths are resolved against the search paths.
     */
    public function load(string $path, int $faceIndex = 0): Font
    {
        $resolved = $this->resolvePath($path);

        return $this->fonts["{$resolved}#{$faceIndex}"] ??= new Font(
            $this->document,
            OpenTypeFont::fromFile($resolved, $faceIndex),
        );
    }

    /** @return list<string> */
    public function searchPaths(): array
    {
        return $this->searchPaths;
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
