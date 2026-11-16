<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Git;

use Sebb\CoverageNotes\Coverage\CoverageSummary;

final readonly class HistoryEntry
{
    public function __construct(
        public string $commitSha,
        public string $shortSha,
        public string $subject,
        public string $committedAt,
        public ?CoverageSummary $summary,
    ) {
    }
}
