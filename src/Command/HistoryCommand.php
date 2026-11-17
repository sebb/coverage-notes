<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Command;

use Sebb\CoverageNotes\Git\GitNotesStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'history',
    description: 'Display recent commits alongside their coverage evolution.',
)]
final readonly class HistoryCommand
{
    public function __construct(
        private ?GitNotesStorage $storage = null,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Maximum number of recent commits to inspect', shortcut: 'l')]
        int $limit = 10,
        #[Option(description: 'Branch or commit reference to inspect', shortcut: 'b')]
        string $branch = 'HEAD',
        #[Option(description: 'Git notes ref namespace (e.g. "coverage" for refs/notes/coverage)')]
        string $ref = GitNotesStorage::DEFAULT_NOTES_REF,
        #[Option(description: 'Git remote name for fetching notes')]
        string $remote = 'origin',
        #[Option(description: 'Fetch coverage notes from the remote before inspecting history')]
        bool $fetch = true,
    ): int {
        $storage = $this->storage ?? new GitNotesStorage();

        try {
            if ($fetch) {
                $storage->fetchNotes($ref, $remote);
            }

            $entries = $storage->getHistory(max(1, $limit), $branch, $ref);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($entries === []) {
            $output->writeln('<comment>No commits found.</comment>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Commit', 'Date', 'Coverage', 'Delta', 'Statements', 'Subject']);

        $count = count($entries);
        for ($i = 0; $i < $count; ++$i) {
            $entry = $entries[$i];
            $summary = $entry->summary;

            // Find previous recorded coverage in the list (older commit) to compute delta
            $previousSummary = null;
            for ($j = $i + 1; $j < $count; ++$j) {
                if ($entries[$j]->summary !== null) {
                    $previousSummary = $entries[$j]->summary;
                    break;
                }
            }

            if ($summary === null) {
                $table->addRow([
                    sprintf('<comment>%s</comment>', $entry->shortSha),
                    $entry->committedAt,
                    '<fg=gray>N/A</>',
                    '<fg=gray>-</>',
                    '<fg=gray>-</>',
                    $entry->subject,
                ]);
                continue;
            }

            $deltaStr = '<fg=gray>-</>';
            if ($previousSummary !== null) {
                $delta = round($summary->project->percentage - $previousSummary->project->percentage, 2);
                if ($delta > 0.0) {
                    $deltaStr = sprintf('<fg=green>+%.2f%%</>', $delta);
                } elseif ($delta < 0.0) {
                    $deltaStr = sprintf('<fg=red>%.2f%%</>', $delta);
                } else {
                    $deltaStr = '<fg=gray>0.00%</>';
                }
            }

            $table->addRow([
                sprintf('<comment>%s</comment>', $entry->shortSha),
                $entry->committedAt,
                sprintf('<options=bold>%.2f%%</>', $summary->project->percentage),
                $deltaStr,
                sprintf('%d/%d', $summary->project->covered, $summary->project->statements),
                $entry->subject,
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
