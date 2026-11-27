<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Command;

use Sebb\CoverageNotes\Git\BaselineResolutionStrategy;
use Sebb\CoverageNotes\Git\GitNotesStorage;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'history',
    description: 'Display recent commits or a commit range alongside their coverage evolution.',
)]
final readonly class HistoryCommand
{
    public function __construct(
        private ?GitNotesStorage $storage = null,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Argument(description: 'Git commit range or reference to inspect (e.g. "v0.1.0..v0.2.0", "main..HEAD")')]
        ?string $range = null,
        #[Option(description: 'Maximum number of commits to inspect (defaults to 10 unless a commit range is given)', shortcut: 'l')]
        ?int $limit = null,
        #[Option(description: 'Branch or commit reference to inspect (defaults to "HEAD"; cannot be combined with the range argument)', shortcut: 'b')]
        ?string $branch = null,
        #[Option(description: 'Git notes ref namespace (e.g. "coverage" for refs/notes/coverage)')]
        string $ref = GitNotesStorage::DEFAULT_NOTES_REF,
        #[Option(description: 'Git remote name for fetching notes')]
        string $remote = 'origin',
        #[Option(description: 'Fetch coverage notes from the remote before inspecting history')]
        bool $fetch = true,
    ): int {
        if ($range !== null && $branch !== null) {
            $output->writeln('<error>Cannot specify both a commit range argument and the --branch option.</error>');

            return Command::FAILURE;
        }

        $target = $range ?? $branch ?? 'HEAD';
        $effectiveLimit = $limit !== null
            ? max(1, $limit)
            : (GitNotesStorage::isCommitRange($target) ? null : GitNotesStorage::DEFAULT_HISTORY_LIMIT);

        $storage = $this->storage ?? new GitNotesStorage();

        try {
            if ($fetch) {
                $storage->fetchNotes($ref, $remote);
            }

            $entries = $storage->getHistory($effectiveLimit, $target, $ref);
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

            // Find previous recorded coverage in the list (older commit) to compute delta
            $previousSummary = null;
            for ($j = $i + 1; $j < $count; ++$j) {
                if ($entries[$j]->summary !== null) {
                    $previousSummary = $entries[$j]->summary;
                    break;
                }
            }

            if ($previousSummary === null) {
                $previousSummary = $storage->resolveBaseline(
                    sprintf('%s^1', $entry->commitSha),
                    BaselineResolutionStrategy::Direct,
                    $ref,
                )?->summary;
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
