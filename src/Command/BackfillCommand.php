<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Command;

use RuntimeException;
use Sebb\CoverageNotes\Coverage\CloverParser;
use Sebb\CoverageNotes\Git\GitNotesStorage;
use Sebb\CoverageNotes\Git\HistoryEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

#[AsCommand(
    name: 'backfill',
    description: 'Backfill missing coverage notes on recent commits using a temporary Git worktree.',
)]
final readonly class BackfillCommand
{
    public function __construct(
        private CloverParser $parser = new CloverParser(),
        private ?GitNotesStorage $storage = null,
    ) {
    }

    public function __invoke(
        OutputInterface $output,
        #[Option(description: 'Maximum number of recent commits to inspect', shortcut: 'l')]
        int $limit = 10,
        #[Option(description: 'Branch or commit reference to inspect', shortcut: 'b')]
        string $branch = 'HEAD',
        #[Option(description: 'Shell command to generate the Clover XML file in the worktree', shortcut: 'c')]
        string $command = 'XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-clover=coverage.xml',
        #[Option(description: 'Relative path to the Clover XML file generated inside the worktree')]
        string $cloverFile = 'coverage.xml',
        #[Option(description: 'Recompute and overwrite commits that already have a coverage note', shortcut: 'f')]
        bool $force = false,
        #[Option(description: 'Git notes ref namespace (e.g. "coverage" for refs/notes/coverage)')]
        string $ref = GitNotesStorage::DEFAULT_NOTES_REF,
        #[Option(description: 'Git remote name for fetching and pushing notes')]
        string $remote = 'origin',
        #[Option(description: 'Fetch coverage notes from the remote before backfilling')]
        bool $fetch = true,
        #[Option(description: 'Push the updated coverage notes ref to the remote repository after backfilling', shortcut: 'p')]
        bool $push = false,
    ): int {
        $storage = $this->storage ?? new GitNotesStorage();

        try {
            if ($fetch && !$storage->fetchNotes($ref, $remote)) {
                throw new RuntimeException(sprintf('Failed to synchronize coverage notes from remote "%s".', $remote));
            }

            $entries = $storage->getHistory(max(1, $limit), $branch, $ref, !$force);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $targets = array_reverse(array_values(array_filter(
            $entries,
            static fn (HistoryEntry $entry): bool => $force || $entry->summary === null,
        )));

        $recordedCount = 0;

        if ($targets === []) {
            $output->writeln('<info>All inspected commits already have coverage notes.</info>');
        } else {
            $repositoryRoot = $storage->getRepositoryRoot();

            foreach ($targets as $entry) {
                $worktreeDir = sys_get_temp_dir() . '/coverage-notes-worktree-' . bin2hex(random_bytes(6));

                try {
                    $storage->addWorktree($worktreeDir, $entry->commitSha);

                    $vendorDir = $repositoryRoot . '/vendor';
                    $worktreeVendor = $worktreeDir . '/vendor';
                    if (!is_dir($worktreeVendor) && !is_link($worktreeVendor) && is_dir($vendorDir)) {
                        (new Process(['cp', '-a', $vendorDir, $worktreeVendor]))->run();
                    }

                    $process = Process::fromShellCommandline($command, $worktreeDir, null, null, null);
                    $process->run();

                    $cloverPath = $worktreeDir . '/' . ltrim($cloverFile, '/');
                    if (!$process->isSuccessful() || !is_file($cloverPath)) {
                        $output->writeln(sprintf(
                            '<comment>Skipping %s (%s): test command failed or "%s" was not generated.</comment>',
                            $entry->shortSha,
                            $entry->subject,
                            $cloverFile,
                        ));
                        continue;
                    }

                    $summary = $this->parser->parseFile($cloverPath, $worktreeDir);
                    $storage->recordNote($summary, $entry->commitSha, $ref);
                    ++$recordedCount;

                    $output->writeln(sprintf(
                        '<info>Recorded coverage note on</info> <comment>%s</comment> <info>(%s): %.2f%% (%d/%d statements).</info>',
                        $entry->shortSha,
                        $entry->subject,
                        $summary->project->percentage,
                        $summary->project->covered,
                        $summary->project->statements,
                    ));
                } catch (Throwable $e) {
                    $output->writeln(sprintf('<comment>Skipping %s (%s): %s</comment>', $entry->shortSha, $entry->subject, $e->getMessage()));
                } finally {
                    $storage->removeWorktree($worktreeDir);
                }
            }
        }

        if ($push && ($recordedCount > 0 || $targets === [])) {
            try {
                $storage->pushNotes($ref, $remote);
                $output->writeln(sprintf('<info>Pushed refs/notes/%s to %s.</info>', ltrim($ref, '/'), $remote));
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
