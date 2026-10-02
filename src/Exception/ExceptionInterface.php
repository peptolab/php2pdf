<?php

declare(strict_types=1);

namespace Php2Pdf\Exception;

use Throwable;

/**
 * Every exception the library throws implements this: catch it to handle them all. Each is also
 * one of PHP's own: an InvalidArgumentException for an argument the method does not accept, a
 * LogicException (StateException) for a call the object's state does not allow, and a
 * RuntimeException (PdfException and its kinds) for what goes wrong at run time.
 *
 * @api
 */
interface ExceptionInterface extends Throwable {}
