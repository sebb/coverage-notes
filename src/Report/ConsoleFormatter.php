<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Report;

use Sebb\CoverageNotes\Diff\CoverageDiff;
use Sebb\CoverageNotes\Diff\ItemChangeType;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleFormatter
{
    public function render(CoverageDiff $diff, OutputInterface $output, int $commitsBehind = 0): void
    {
        $project = $diff->current->project;

        if (!$diff->hasBaseline() || $diff->baseline === null) {
            $output->writeln(sprintf(
                '<info>Coverage:</info> <options=bold>%.2f%%</> (%d/%d statements) — <comment>no baseline note found</comment>',
                $project->percentage,
                $project->covered,
                $project->statements,
            ));
            return;
        }

        $shortCommit = $diff->baselineCommit !== null ? substr($diff->baselineCommit, 0, 7) : 'unknown';
        $behindNote = $commitsBehind > 0 ? sprintf(' (%d commit%s behind target)', $commitsBehind, $commitsBehind === 1 ? '' : 's') : '';
        $deltaFormatted = $this->formatColoredDelta($diff->projectPercentageDelta ?? 0.0);

        $output->writeln(sprintf(
            '<info>Overall Coverage:</info> %.2f%% ➔ <options=bold>%.2f%%</> (%s vs <comment>%s</comment>%s | %d/%d statements)',
            $diff->baseline->project->percentage,
            $project->percentage,
            $deltaFormatted,
            $shortCommit,
            $behindNote,
            $project->covered,
            $project->statements,
        ));

        if ($diff->changedItems === []) {
            $output->writeln('No item-level coverage changes.');
            return;
        }

        $output->writeln('');
        $table = new Table($output);
        $table->setHeaders(['Status', 'Class / File', 'Baseline', 'Current', 'Delta', 'Statements']);

        foreach ($diff->changedItems as $itemDiff) {
            $statusLabel = match ($itemDiff->type) {
                ItemChangeType::Added => '<fg=green>NEW</>',
                ItemChangeType::Removed => '<fg=yellow>REMOVED</>',
                ItemChangeType::Modified => $itemDiff->percentageDelta >= 0.0
                    ? '<fg=green>CHANGED</>'
                    : '<fg=red>CHANGED</>',
            };

            $baseStr = $itemDiff->baseline !== null ? sprintf('%.2f%%', $itemDiff->baseline->percentage) : 'N/A';
            $currStr = $itemDiff->current !== null ? sprintf('%.2f%%', $itemDiff->current->percentage) : 'N/A';
            $deltaStr = match ($itemDiff->type) {
                ItemChangeType::Added => '<fg=green>new</>',
                ItemChangeType::Removed => '<fg=yellow>removed</>',
                ItemChangeType::Modified => $this->formatColoredDelta($itemDiff->percentageDelta),
            };
            $stmtsStr = $itemDiff->current !== null
                ? sprintf('%d/%d', $itemDiff->current->covered, $itemDiff->current->statements)
                : sprintf('was %d/%d', $itemDiff->baseline?->covered ?? 0, $itemDiff->baseline?->statements ?? 0);

            $table->addRow([
                $statusLabel,
                $itemDiff->name,
                $baseStr,
                $currStr,
                $deltaStr,
                $stmtsStr,
            ]);
        }

        $table->render();
    }

    private function formatColoredDelta(float $delta): string
    {
        if ($delta > 0.0) {
            return sprintf('<fg=green>+%.2f%%</>', $delta);
        }

        if ($delta < 0.0) {
            return sprintf('<fg=red>%.2f%%</>', $delta);
        }

        return '<fg=gray>0.00%</>';
    }
}
