<?php

declare(strict_types=1);

namespace Php2Pdf\Exception;

use LogicException;

/**
 * A call the object's state does not allow: drawing on a finished canvas or page, using a closed
 * document, restoring a graphics state that was never saved. A bug in the calling code.
 *
 * @api
 */
final class StateException extends LogicException implements ExceptionInterface {}
