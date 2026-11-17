<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Diff;

use Sebb\CoverageNotes\Coverage\CoverageSummary;

final readonly class CoverageDiffer
{
    public function diff(
        CoverageSummary $current,
        ?CoverageSummary $baseline = null,
        ?string $baselineCommit = null,
        int $commitsBehindTarget = 0,
    ): CoverageDiff {
        if ($baseline === null) {
            return new CoverageDiff(
                current: $current,
                baseline: null,
                baselineCommit: null,
                changedItems: [],
                commitsBehindTarget: 0,
            );
        }

        $allKeys = array_unique(array_merge(
            array_keys($baseline->items),
            array_keys($current->items),
        ));
        sort($allKeys);

        $changedItems = [];

        foreach ($allKeys as $key) {
            $baseItem = $baseline->items[$key] ?? null;
            $currItem = $current->items[$key] ?? null;

            if ($baseItem === null && $currItem !== null) {
                $changedItems[$key] = new ItemDiff(
                    name: $key,
                    type: ItemChangeType::Added,
                    baseline: null,
                    current: $currItem,
                );
                continue;
            }

            if ($baseItem !== null && $currItem === null) {
                $changedItems[$key] = new ItemDiff(
                    name: $key,
                    type: ItemChangeType::Removed,
                    baseline: $baseItem,
                    current: null,
                );
                continue;
            }

            if (
                $baseItem !== null
                && $currItem !== null
                && (
                    $baseItem->percentage !== $currItem->percentage
                    || $baseItem->statements !== $currItem->statements
                    || $baseItem->covered !== $currItem->covered
                )
            ) {
                $changedItems[$key] = new ItemDiff(
                    name: $key,
                    type: ItemChangeType::Modified,
                    baseline: $baseItem,
                    current: $currItem,
                );
            }
        }

        return new CoverageDiff(
            current: $current,
            baseline: $baseline,
            baselineCommit: $baselineCommit,
            changedItems: $changedItems,
            commitsBehindTarget: $commitsBehindTarget,
        );
    }
}
