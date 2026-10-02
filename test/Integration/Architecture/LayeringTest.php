<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_values;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function ksort;
use function preg_match_all;
use function sort;
use function str_replace;
use function strlen;
use function substr;

/**
 * The library's namespaces in layers: each may use those in lower layers, and the namespaces of
 * its own layer only where listed. A lower layer that needs something of a higher one declares
 * an interface for it instead (see docs/development.md). RATCHET would list breaks being worked
 * off; it is empty, and should stay so.
 */
#[Group('integration')]
final class LayeringTest extends TestCase
{
    /** Each top-level namespace's layer, from the bottom. */
    private const array LAYERS = [
        'Exception'  => 0,
        'Core'       => 1,
        'Geometry'   => 2,
        'Navigation' => 2,
        'Color'      => 3,
        'Fit'        => 3,
        'Font'       => 4,
        'Canvas'     => 5,
        'Template'   => 5,
        'Image'      => 6,
        'Import'     => 6,
        'Flow'       => 7,
        'Text'       => 7,
        'Table'      => 7,
        'Svg'        => 8,
        'Document'   => 9,
    ];

    /** Uses within a layer that are part of the design. */
    private const array WITHIN_LAYER = [
        'Canvas>Template' => 'templates are drawing surfaces, and tiling patterns are templates',
        'Template>Canvas' => 'a template is drawn on a canvas of its own',
        'Text>Flow'       => 'a textflow is a flowable',
        'Table>Flow'      => 'a table is a flowable',
        'Table>Text'      => 'cells hold textflows',
    ];

    /** Uses that break the layering today, and the work that removes each. */
    private const array RATCHET = [];

    /**
     * The top-level namespaces each namespace uses, with the files that use them.
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function uses(): array
    {
        $src   = dirname(__DIR__, levels: 3) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $src,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        $uses = [];
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $path      = str_replace('\\', replace: '/', subject: substr($file->getPathname(), strlen($src) + 1));
            $namespace = explode(
                separator: '/',
                string: $path,
            )[0];
            $uses[$namespace] ??= [];
            $matches          = [];
            preg_match_all(
                '/^use Php2Pdf\\\\([A-Za-z]+)\\\\/m',
                (string) file_get_contents($file->getPathname()),
                $matches,
            );
            foreach ($matches[1] as $target) {
                if ($target === $namespace) {
                    continue;
                }
                $uses[$namespace][$target][] = $path;
            }
        }
        foreach ($uses as &$targets) {
            foreach ($targets as &$paths) {
                sort($paths);
            }
        }

        return $uses;
    }

    #[Test]
    public function everyNamespaceHasALayer(): void
    {
        $namespaces = array_keys(self::uses());

        self::assertSame([], array_values(array_diff($namespaces, array_keys(self::LAYERS))));
    }

    #[Test]
    public function namespacesUseOnlyTheLayersBelowThem(): void
    {
        $breaks = [];
        foreach (self::uses() as $from => $targets) {
            foreach ($targets as $to => $files) {
                $edge = "{$from}>{$to}";
                if (
                    (self::LAYERS[$to] ?? 0) < (self::LAYERS[$from] ?? 0)
                    || array_key_exists($edge, self::WITHIN_LAYER)
                ) {
                    continue;
                }
                $breaks[$edge] = implode(', ', $files);
            }
        }
        ksort($breaks);
        $listed = self::RATCHET;
        ksort($listed);

        self::assertSame(
            array_keys($listed),
            array_keys($breaks),
            'A use breaks the layering (fix it; do not list it), or a listed one is gone (remove it from RATCHET).',
        );
    }
}
