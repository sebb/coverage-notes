<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Command;

use RuntimeException;
use Sebb\CoverageNotes\Coverage\CloverParser;
use Sebb\CoverageNotes\Git\GitNotesStorage;
use Sebb\CoverageNotes\Git\HistoryEntry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

#[AsCommand(
    name: 'backfill',
    description: 'Backfill missing coverage notes on recent commits or a commit range using a temporary Git worktree.',
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
        #[Argument(description: 'Git commit range or reference to inspect (e.g. "v0.1.0..v0.2.0", "main..HEAD")')]
        ?string $range = null,
        #[Option(description: 'Maximum number of commits to inspect (defaults to 10 unless a commit range is given)', shortcut: 'l')]
        ?int $limit = null,
        #[Option(description: 'Branch or commit reference to inspect (defaults to "HEAD"; cannot be combined with the range argument)', shortcut: 'b')]
        ?string $branch = null,
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
            if ($fetch && !$storage->fetchNotes($ref, $remote)) {
                throw new RuntimeException(sprintf('Failed to synchronize coverage notes from remote "%s".', $remote));
            }

            $entries = $storage->getHistory($effectiveLimit, $target, $ref, !$force);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if ($entries === []) {
            $output->writeln('<comment>No commits found.</comment>');

            return Command::SUCCESS;
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

                    $this->prepareWorktreeVendor($repositoryRoot, $worktreeDir);

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

    private function prepareWorktreeVendor(string $repositoryRoot, string $worktreeDir): void
    {
        $vendorDir = $repositoryRoot . '/vendor';
        $worktreeVendor = $worktreeDir . '/vendor';

        if (!is_dir($worktreeVendor) && !is_link($worktreeVendor) && is_dir($vendorDir)) {
            (new Process(['cp', '-a', $vendorDir, $worktreeVendor]))->mustRun();
        }

        if (!is_file($worktreeDir . '/composer.json')) {
            return;
        }

        if (
            !is_dir($worktreeVendor)
            || $this->fileHash($worktreeDir . '/composer.json') !== $this->fileHash($repositoryRoot . '/composer.json')
            || $this->fileHash($worktreeDir . '/composer.lock') !== $this->fileHash($repositoryRoot . '/composer.lock')
        ) {
            $composerCommand = is_file($worktreeDir . '/composer.lock')
                ? ['composer', 'install', '--no-interaction', '--no-progress', '--no-scripts']
                : ['composer', 'dump-autoload', '--no-interaction', '--no-scripts'];

            (new Process($composerCommand, $worktreeDir))->mustRun();
        }
    }

    private function fileHash(string $path): ?string
    {
        return is_file($path) ? (md5_file($path) ?: null) : null;
    }
}
