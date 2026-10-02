<?php

declare(strict_types=1);

namespace Php2Pdf\Exception;

use RuntimeException;

/**
 * Something went wrong at run time: a file that cannot be parsed or read, content a conformance
 * level does not allow, a layout that cannot be made, a tool or extension that is missing.
 * Its kinds say which; catch ExceptionInterface to handle everything the library throws.
 *
 * @api
 */
class PdfException extends RuntimeException implements ExceptionInterface {}
