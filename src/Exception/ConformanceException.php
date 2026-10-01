<?php

declare(strict_types=1);

namespace Php2Pdf\Exception;

/**
 * The document would break the rules of the PDF standard it declares, such as PDF/X-4.
 *
 * @api
 */
final class ConformanceException extends PdfException {}
