<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

/**
 * What happens when text is in a script that needs shaping the library cannot do yet (an Indic
 * script, for example), which would be set wrongly.
 *
 * @api
 */
enum ScriptCheck
{
    /** Throw a PdfException naming the script and the character. */
    case Error;

    /** Set the text unshaped and record a warning in the font registry, once per font and script. */
    case Warn;

    /** Set the text unshaped silently. */
    case Ignore;
}
