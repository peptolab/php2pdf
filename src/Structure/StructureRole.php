<?php

declare(strict_types=1);

namespace Php2Pdf\Structure;

/**
 * The standard structure types of tagged PDF (ISO 32000-1, 14.8.4) that content can be given:
 * what assistive technology reads it as. Textflows tag their paragraphs P (or the role their
 * style gives), tables their rows and cells, and placed artwork with alternate text Figure.
 *
 * @api
 */
enum StructureRole: string
{
    case Document = 'Document';

    case Section = 'Sect';

    case Paragraph = 'P';

    case H1 = 'H1';

    case H2 = 'H2';

    case H3 = 'H3';

    case H4 = 'H4';

    case H5 = 'H5';

    case H6 = 'H6';

    case Caption = 'Caption';

    case List = 'L';

    case ListItem = 'LI';

    case Label = 'Lbl';

    case ListBody = 'LBody';

    case Table = 'Table';

    case TableRow = 'TR';

    case TableHeader = 'TH';

    case TableData = 'TD';

    case Figure = 'Figure';

    case Link = 'Link';
}
