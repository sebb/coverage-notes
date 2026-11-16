<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Diff;

use Sebb\CoverageNotes\Coverage\ItemMetrics;

final readonly class ItemDiff
{
    public float $percentageDelta;
    public int $statementsDelta;
    public int $coveredDelta;

    public function __construct(
        public string $name,
        public ItemChangeType $type,
        public ?ItemMetrics $baseline,
        public ?ItemMetrics $current,
    ) {
        $basePct = $baseline?->percentage ?? 0.0;
        $currPct = $current?->percentage ?? 0.0;

        $this->percentageDelta = match ($type) {
            ItemChangeType::Added => $currPct,
            ItemChangeType::Removed => -$basePct,
            ItemChangeType::Modified => round($currPct - $basePct, 2),
        };

        $this->statementsDelta = ($current?->statements ?? 0) - ($baseline?->statements ?? 0);
        $this->coveredDelta = ($current?->covered ?? 0) - ($baseline?->covered ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'baseline' => $this->baseline?->toArray(),
            'current' => $this->current?->toArray(),
            'percentage_delta' => $this->percentageDelta,
            'statements_delta' => $this->statementsDelta,
            'covered_delta' => $this->coveredDelta,
        ];
    }
}
