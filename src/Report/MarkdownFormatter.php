<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Report;

use Sebb\CoverageNotes\Diff\CoverageDiff;
use Sebb\CoverageNotes\Diff\ItemChangeType;
use Sebb\CoverageNotes\Diff\ItemDiff;

final readonly class MarkdownFormatter
{
    public function format(CoverageDiff $diff, int $commitsBehind = 0): string
    {
        $project = $diff->current->project;

        if ($diff->baseline === null) {
            return implode("\n", [
                sprintf('### 📊 Coverage: %.2f%%', $project->percentage),
                '',
                sprintf(
                    '%d/%d statements · *No baseline coverage note found*',
                    $project->covered,
                    $project->statements,
                ),
            ]) . "\n";
        }

        $shortCommit = $diff->baselineCommit !== null ? substr($diff->baselineCommit, 0, 7) : 'unknown';
        $behindSuffix = $commitsBehind > 0 ? sprintf(' *(%d commit%s behind target)*', $commitsBehind, $commitsBehind === 1 ? '' : 's') : '';
        $delta = $diff->projectPercentageDelta ?? 0.0;
        $baselinePercentage = $diff->baseline->project->percentage;

        [$emoji, $deltaSuffix, $leadText] = match (true) {
            $delta > 0.0 => ['🟢', sprintf(' (+%.2f%%)', $delta), sprintf('Up from %.2f%%', $baselinePercentage)],
            $delta < 0.0 => ['🔴', sprintf(' (%.2f%%)', $delta), sprintf('Down from %.2f%%', $baselinePercentage)],
            default => ['⚪', '', $diff->changedItems === [] ? 'No coverage changes' : 'Unchanged'],
        };

        $lines = [
            sprintf('### %s Coverage: %.2f%%%s', $emoji, $project->percentage, $deltaSuffix),
            '',
            sprintf(
                '%s vs `%s`%s · %d/%d statements',
                $leadText,
                $shortCommit,
                $behindSuffix,
                $project->covered,
                $project->statements,
            ),
        ];

        if ($diff->changedItems === []) {
            return implode("\n", $lines) . "\n";
        }

        $lines[] = '';
        $lines[] = '| Class / File | Coverage | Change | Statements |';
        $lines[] = '| :--- | ---: | :--- | ---: |';

        foreach ($this->sortItems($diff->changedItems) as $itemDiff) {
            $lines[] = $this->formatItemRow($itemDiff);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, ItemDiff> $items
     *
     * @return list<ItemDiff>
     */
    private function sortItems(array $items): array
    {
        $sorted = array_values($items);

        usort(
            $sorted,
            fn (ItemDiff $a, ItemDiff $b): int => $this->itemSortKey($a) <=> $this->itemSortKey($b),
        );

        return $sorted;
    }

    /**
     * @return array{int, float, string}
     */
    private function itemSortKey(ItemDiff $item): array
    {
        return match ($item->type) {
            ItemChangeType::Added => [1, 0.0, $item->name],
            ItemChangeType::Removed => [4, 0.0, $item->name],
            ItemChangeType::Modified => match (true) {
                $item->percentageDelta < 0.0 => [0, $item->percentageDelta, $item->name],
                $item->percentageDelta > 0.0 => [2, -$item->percentageDelta, $item->name],
                default => [3, 0.0, $item->name],
            },
        };
    }

    private function formatItemRow(ItemDiff $itemDiff): string
    {
        $escapedName = str_replace('|', '\|', $itemDiff->name);

        if ($itemDiff->type === ItemChangeType::Removed) {
            $baseline = $itemDiff->baseline;
            $coverageStr = $baseline !== null ? sprintf('~~%.2f%%~~', $baseline->percentage) : '—';
            $statementsStr = $baseline !== null ? sprintf('~~%d/%d~~', $baseline->covered, $baseline->statements) : '—';

            return sprintf(
                '| ~~`%s`~~ | %s | 🗑️ Removed | %s |',
                $escapedName,
                $coverageStr,
                $statementsStr,
            );
        }

        $current = $itemDiff->current;
        $coverageStr = $current !== null ? sprintf('%.2f%%', $current->percentage) : '—';
        $changeStr = $itemDiff->type === ItemChangeType::Added
            ? '🆕 New'
            : match (true) {
                $itemDiff->percentageDelta > 0.0 => sprintf('🟢 **+%.2f%%**', $itemDiff->percentageDelta),
                $itemDiff->percentageDelta < 0.0 => sprintf('🔴 **%.2f%%**', $itemDiff->percentageDelta),
                default => '⚪ Unchanged',
            };

        if (
            $itemDiff->type === ItemChangeType::Modified
            && $itemDiff->percentageDelta === 0.0
            && $itemDiff->baseline !== null
            && $current !== null
        ) {
            $statementsStr = sprintf(
                '%d/%d → %d/%d',
                $itemDiff->baseline->covered,
                $itemDiff->baseline->statements,
                $current->covered,
                $current->statements,
            );
        } else {
            $statementsStr = $current !== null ? sprintf('%d/%d', $current->covered, $current->statements) : '—';
        }

        return sprintf(
            '| `%s` | %s | %s | %s |',
            $escapedName,
            $coverageStr,
            $changeStr,
            $statementsStr,
        );
    }
}
