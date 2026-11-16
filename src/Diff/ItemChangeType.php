<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Diff;

enum ItemChangeType: string
{
    case Added = 'added';
    case Modified = 'modified';
    case Removed = 'removed';
}
