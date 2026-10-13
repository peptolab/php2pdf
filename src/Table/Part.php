<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

/**
 * The parts of a table its rows belong to.
 *
 * @internal
 */
enum Part
{
    case Header;

    case Body;

    case Footer;
}
