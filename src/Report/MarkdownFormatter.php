<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Report;

use Sebb\CoverageNotes\Diff\CoverageDiff;
use Sebb\CoverageNotes\Diff\ItemChangeType;

final readonly class MarkdownFormatter
{
    public function format(CoverageDiff $diff, int $commitsBehind = 0): string
    {
        $project = $diff->current->project;
        $lines = ['### Coverage Evolution'];

        if (!$diff->hasBaseline() || $diff->baseline === null) {
            $lines[] = '';
            $lines[] = sprintf(
                '**Overall:** **%.2f%%** (`%d/%d` statements) — *No baseline coverage note found.*',
                $project->percentage,
                $project->covered,
                $project->statements,
            );

            return implode("\n", $lines) . "\n";
        }

        $shortCommit = $diff->baselineCommit !== null ? substr($diff->baselineCommit, 0, 7) : 'unknown';
        $behindSuffix = $commitsBehind > 0 ? sprintf(' *(%d commit%s behind target)*', $commitsBehind, $commitsBehind === 1 ? '' : 's') : '';
        $delta = $diff->projectPercentageDelta ?? 0.0;
        $deltaSign = $delta > 0.0 ? '+' : '';

        $lines[] = '';
        $lines[] = sprintf(
            '**Overall:** %.2f%% ➔ **%.2f%%** (`%s%.2f%%` vs `%s`%s | `%d/%d` statements)',
            $diff->baseline->project->percentage,
            $project->percentage,
            $deltaSign,
            $delta,
            $shortCommit,
            $behindSuffix,
            $project->covered,
            $project->statements,
        );

        if ($diff->changedItems === []) {
            $lines[] = '';
            $lines[] = '_No class or file coverage changes._';

            return implode("\n", $lines) . "\n";
        }

        $lines[] = '';
        $lines[] = '| Status | Class / File | Baseline | Current | Delta | Statements |';
        $lines[] = '| :--- | :--- | ---: | ---: | ---: | ---: |';

        foreach ($diff->changedItems as $itemDiff) {
            $status = match ($itemDiff->type) {
                ItemChangeType::Added => '🆕 New',
                ItemChangeType::Removed => '🗑️ Removed',
                ItemChangeType::Modified => $itemDiff->percentageDelta >= 0.0 ? '✅ Improved' : '⚠️ Decreased',
            };

            $baseStr = $itemDiff->baseline !== null ? sprintf('%.2f%%', $itemDiff->baseline->percentage) : 'N/A';
            $currStr = $itemDiff->current !== null ? sprintf('%.2f%%', $itemDiff->current->percentage) : 'N/A';
            $deltaStr = match ($itemDiff->type) {
                ItemChangeType::Added => 'New',
                ItemChangeType::Removed => 'Removed',
                ItemChangeType::Modified => sprintf('%s%.2f%%', $itemDiff->percentageDelta > 0.0 ? '+' : '', $itemDiff->percentageDelta),
            };
            $stmtsStr = $itemDiff->current !== null
                ? sprintf('`%d/%d`', $itemDiff->current->covered, $itemDiff->current->statements)
                : sprintf('was `%d/%d`', $itemDiff->baseline?->covered ?? 0, $itemDiff->baseline?->statements ?? 0);

            $lines[] = sprintf(
                '| %s | `%s` | %s | %s | %s | %s |',
                $status,
                $itemDiff->name,
                $baseStr,
                $currStr,
                $deltaStr,
                $stmtsStr,
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
