<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Git;

use Sebb\CoverageNotes\Coverage\CoverageSummary;

final readonly class ResolvedBaseline
{
    public function __construct(
        public string $commitSha,
        public CoverageSummary $summary,
        public int $commitsBehindTarget = 0,
    ) {
    }
}
