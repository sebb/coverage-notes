<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Report;

enum ReportFormat: string
{
    case Console = 'console';
    case Markdown = 'markdown';
    case Json = 'json';
}
