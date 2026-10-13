<?php

declare(strict_types=1);

namespace Php2Pdf\Exception;

/**
 * An encrypted input file cannot be opened as asked: it needs a password, the password given does
 * not open it, or its permissions forbid copying its content or assembling its pages into another
 * document.
 *
 * @api
 */
final class PermissionException extends PdfException {}
