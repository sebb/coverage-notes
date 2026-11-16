<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Git;

enum BaselineResolutionStrategy: string
{
    case MergeBase = 'merge-base';
    case Direct = 'direct';
}
