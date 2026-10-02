<?php

declare(strict_types=1);

namespace Php2Pdf\Font\HarfBuzz;

use FFI;
use FFI\CData;
use Override;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\ExternalShaperInterface;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\TextDirection;
use Throwable;
use WeakMap;

use function count;
use function explode;
use function implode;
use function sprintf;
use function strlen;
use function trim;

use const PHP_OS_FAMILY;

/**
 * HarfBuzz through PHP's FFI extension: the library is loaded once, and each font's HarfBuzz face
 * is made once and kept while both the font and the shaper live. FFI must be enabled for the process (ffi.enable);
 * web servers often allow it only for preloaded code, where HarfBuzzCli serves instead.
 *
 * @api
 */
final class HarfBuzzFfi implements ExternalShaperInterface
{
    private const string DEFINITIONS = <<<'C'
        typedef struct hb_blob_t hb_blob_t;
        typedef struct hb_face_t hb_face_t;
        typedef struct hb_font_t hb_font_t;
        typedef struct hb_buffer_t hb_buffer_t;
        typedef union { uint32_t u32; int32_t i32; uint16_t u16[2]; int16_t i16[2]; uint8_t u8[4]; int8_t i8[4]; } hb_var_int_t;
        typedef struct { uint32_t codepoint; uint32_t mask; uint32_t cluster; hb_var_int_t var1; hb_var_int_t var2; } hb_glyph_info_t;
        typedef struct { int32_t x_advance; int32_t y_advance; int32_t x_offset; int32_t y_offset; hb_var_int_t var; } hb_glyph_position_t;
        typedef struct { uint32_t tag; uint32_t value; unsigned int start; unsigned int end; } hb_feature_t;
        hb_blob_t *hb_blob_create(const char *data, unsigned int length, int mode, void *user_data, void *destroy);
        void hb_blob_destroy(hb_blob_t *blob);
        hb_face_t *hb_face_create(hb_blob_t *blob, unsigned int index);
        void hb_face_destroy(hb_face_t *face);
        hb_font_t *hb_font_create(hb_face_t *face);
        void hb_font_destroy(hb_font_t *font);
        hb_buffer_t *hb_buffer_create(void);
        void hb_buffer_destroy(hb_buffer_t *buffer);
        void hb_buffer_add_utf8(hb_buffer_t *buffer, const char *text, int text_length, unsigned int item_offset, int item_length);
        void hb_buffer_set_direction(hb_buffer_t *buffer, int direction);
        void hb_buffer_set_script(hb_buffer_t *buffer, uint32_t script);
        void hb_buffer_guess_segment_properties(hb_buffer_t *buffer);
        uint32_t hb_script_from_string(const char *str, int len);
        const void *hb_language_from_string(const char *str, int len);
        void hb_buffer_set_language(hb_buffer_t *buffer, const void *language);
        int hb_feature_from_string(const char *str, int len, hb_feature_t *feature);
        void hb_shape(hb_font_t *font, hb_buffer_t *buffer, const hb_feature_t *features, unsigned int num_features);
        hb_glyph_info_t *hb_buffer_get_glyph_infos(hb_buffer_t *buffer, unsigned int *length);
        hb_glyph_position_t *hb_buffer_get_glyph_positions(hb_buffer_t *buffer, unsigned int *length);
        C;

    /** Where HarfBuzz is looked for, by operating system family, when no library is given. */
    private const array LIBRARIES = [
        'Darwin'  => [
            'libharfbuzz.0.dylib',
            '/opt/homebrew/lib/libharfbuzz.0.dylib',
            '/usr/local/lib/libharfbuzz.0.dylib',
        ],
        'Windows' => ['harfbuzz.dll', 'libharfbuzz-0.dll'],
        'Linux'   => ['libharfbuzz.so.0', 'libharfbuzz.so'],
    ];

    /** HarfBuzz's directions, and its memory mode that copies a blob's data. */
    private const int LTR       = 4;
    private const int RTL       = 5;
    private const int DUPLICATE = 0;

    private readonly FFI $ffi;

    /** @var WeakMap<OpenTypeFont, array{0: CData, 1: HarfBuzzHandles}> each font program's HarfBuzz font, and what frees it */
    private WeakMap $faces;

    /**
     * @param string|null $library the HarfBuzz library: a path, or a name the system's loader
     *                             finds; by default the usual names and Homebrew's locations
     *
     * @throws PdfException when FFI is unavailable or the library cannot be loaded
     */
    public function __construct(?string $library = null)
    {
        $candidates = null === $library ? self::LIBRARIES[PHP_OS_FAMILY] ?? self::LIBRARIES['Linux'] : [$library];
        $failure    = '';
        $ffi        = null;
        foreach ($candidates as $candidate) {
            try {
                $ffi = FFI::cdef(self::DEFINITIONS, $candidate);
                break;
            } catch (Throwable $e) {
                $failure = explode('(', $e->getMessage(), limit: 2)[0];
            }
        }
        $this->ffi = $ffi ?? throw new PdfException(sprintf(
            'HarfBuzz could not be loaded through FFI (tried %s): %s',
            implode(', ', $candidates),
            trim($failure),
        ));
        $this->faces = new WeakMap();
    }

    #[Override]
    public function shape(
        OpenTypeFont $font,
        string $text,
        string $script,
        TextDirection $direction,
        array $features,
        ?string $language = null,
    ): array {
        $buffer = $this->call('hb_buffer_create');
        try {
            $this->call('hb_buffer_add_utf8', $buffer, $text, strlen($text), 0, strlen($text));
            $this->call(
                'hb_buffer_set_direction',
                $buffer,
                TextDirection::RightToLeft === $direction ? self::RTL : self::LTR,
            );
            $this->call(
                'hb_buffer_set_script',
                $buffer,
                $this->call('hb_script_from_string', $script, strlen($script)),
            );
            if (null !== $language) {
                $this->call(
                    'hb_buffer_set_language',
                    $buffer,
                    $this->call('hb_language_from_string', $language, strlen($language)),
                );
            }
            $this->call('hb_buffer_guess_segment_properties', $buffer);
            $list = $this->features($features);
            $this->call('hb_shape', $this->font($font), $buffer, $list, count($features));
            $length = $this->ffi->new('unsigned int') ?? throw new PdfException(
                'HarfBuzz: cannot allocate a length.',
            );
            $infos     = $this->call('hb_buffer_get_glyph_infos', $buffer, FFI::addr($length));
            $positions = $this->call('hb_buffer_get_glyph_positions', $buffer, null);
            $glyphs    = [];
            for ($k = 0; $k < $length->cdata; ++$k) {
                $info     = $infos[$k];
                $position = $positions[$k];
                $glyphs[] = [
                    $info->codepoint,
                    $info->cluster,
                    $position->x_advance,
                    $position->y_advance,
                    $position->x_offset,
                    $position->y_offset,
                ];
            }

            return $glyphs;
        } finally {
            $this->call('hb_buffer_destroy', $buffer);
        }
    }

    /** Calls a HarfBuzz function. */
    private function call(string $function, mixed ...$arguments): mixed
    {
        return $this->ffi->{$function}(...$arguments);
    }

    /**
     * The features as HarfBuzz feature records, or null for none.
     *
     * @param list<string> $features
     *
     * @throws InvalidArgumentException for a feature HarfBuzz cannot read
     */
    private function features(array $features): ?CData
    {
        if ([] === $features) {
            return null;
        }
        $list = $this->ffi->new(sprintf('hb_feature_t[%d]', count($features))) ?? throw new PdfException(
            'HarfBuzz: cannot allocate features.',
        );
        foreach ($features as $k => $feature) {
            if (0 !== $this->call('hb_feature_from_string', $feature, strlen($feature), FFI::addr($list[$k]))) {
                continue;
            }

            throw new InvalidArgumentException(sprintf('HarfBuzz cannot read the feature "%s".', $feature));
        }

        return $list;
    }

    /**
     * The HarfBuzz font for a font program, made the first time it is shaped.
     *
     * @throws PdfException when HarfBuzz cannot make it
     */
    private function font(OpenTypeFont $font): CData
    {
        $face = $this->faces[$font] ?? null;
        if (null === $face) {
            $ffi    = $this->ffi;
            $data   = $font->data();
            $blob   = $this->call('hb_blob_create', $data, strlen($data), self::DUPLICATE, null, null);
            $hbFace = $this->call('hb_face_create', $blob, $font->faceIndex);
            $hbFont = $this->call('hb_font_create', $hbFace);
            $face   = [
                $hbFont instanceof CData ? $hbFont : throw new PdfException('HarfBuzz: cannot create a font.'),
                new HarfBuzzHandles(static function () use ($ffi, $blob, $hbFace, $hbFont): void {
                    foreach ([
                        'hb_font_destroy' => $hbFont,
                        'hb_face_destroy' => $hbFace,
                        'hb_blob_destroy' => $blob,
                    ] as $function => $handle) {
                        $ffi->{$function}($handle);
                    }
                }),
            ];
            $this->faces[$font] = $face;
        }

        return $face[0];
    }
}
