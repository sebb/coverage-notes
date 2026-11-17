<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Diff;

use Sebb\CoverageNotes\Coverage\CoverageSummary;
use Symfony\Component\Serializer\Attribute\Ignore;

final readonly class CoverageDiff
{
    public ?float $projectPercentageDelta;
    public ?int $projectStatementsDelta;
    public ?int $projectCoveredDelta;

    /**
     * @param array<string, ItemDiff> $changedItems
     */
    public function __construct(
        public CoverageSummary $current,
        public ?CoverageSummary $baseline = null,
        public ?string $baselineCommit = null,
        public array $changedItems = [],
        public int $commitsBehindTarget = 0,
    ) {
        if ($baseline !== null) {
            $this->projectPercentageDelta = round($current->project->percentage - $baseline->project->percentage, 2);
            $this->projectStatementsDelta = $current->project->statements - $baseline->project->statements;
            $this->projectCoveredDelta = $current->project->covered - $baseline->project->covered;
        } else {
            $this->projectPercentageDelta = null;
            $this->projectStatementsDelta = null;
            $this->projectCoveredDelta = null;
        }
    }

    #[Ignore]
    public function hasBaseline(): bool
    {
        return $this->baseline !== null;
    }
}
