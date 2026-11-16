<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Diff;

use Sebb\CoverageNotes\Coverage\CoverageSummary;

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

    public function hasBaseline(): bool
    {
        return $this->baseline !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'baseline_commit' => $this->baselineCommit,
            'project' => [
                'baseline' => $this->baseline?->project->toArray(),
                'current' => $this->current->project->toArray(),
                'percentage_delta' => $this->projectPercentageDelta,
                'statements_delta' => $this->projectStatementsDelta,
                'covered_delta' => $this->projectCoveredDelta,
            ],
            'changed_items' => array_values(array_map(
                static fn(ItemDiff $diff): array => $diff->toArray(),
                $this->changedItems,
            )),
        ];
    }
}
