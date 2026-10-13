<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use Php2Pdf\Color\IccProfile;

use function str_pad;
use function str_repeat;
use function trim;

/** A 128-byte ICC header naming a colour space and nothing else: enough for an output intent. */
final class StubIccProfile
{
    /** @param string $space a four-character ICC colour space, such as 'CMYK' or 'GRAY' */
    public static function of(string $space): IccProfile
    {
        return new IccProfile(
            str_pad(str_repeat("\0", times: 16) . $space, length: 128, pad_string: "\0"),
            'test-' . trim($space),
        );
    }
}
