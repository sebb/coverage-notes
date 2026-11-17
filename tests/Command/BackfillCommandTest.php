<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sebb\CoverageNotes\Command\BackfillCommand;
use Sebb\CoverageNotes\Coverage\CoverageSummary;
use Sebb\CoverageNotes\Coverage\ItemMetrics;
use Sebb\CoverageNotes\Git\GitNotesStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class BackfillCommandTest extends TestCase
{
    private string $tempRootDir;
    private string $tempRemoteDir;
    private string $tempRepoDir;

    protected function setUp(): void
    {
        $this->tempRootDir = sys_get_temp_dir() . '/coverage-notes-backfill-' . bin2hex(random_bytes(6));
        $this->tempRemoteDir = $this->tempRootDir . '/remote.git';
        $this->tempRepoDir = $this->tempRootDir . '/repo';

        mkdir($this->tempRemoteDir, 0777, true);
        mkdir($this->tempRepoDir, 0777, true);

        $this->runGit(['init', '--bare'], $this->tempRemoteDir);
        $this->runGit(['init', '-b', 'main']);
        $this->runGit(['config', 'user.name', 'Test User']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
        $this->runGit(['remote', 'add', 'origin', $this->tempRemoteDir]);

        // Create an untracked vendor directory to verify symlink creation inside worktrees
        mkdir($this->tempRepoDir . '/vendor', 0777, true);
        file_put_contents($this->tempRepoDir . '/.gitignore', "/vendor/\n");
        $this->runGit(['add', '.gitignore']);
        $this->runGit(['commit', '-m', 'Add gitignore']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRootDir)) {
            (new Process(['rm', '-rf', $this->tempRootDir]))->run();
        }
    }

    public function testBackfillsMissingCommitsSkipsFailuresAndSupportsForceAndPush(): void
    {
        $storage = new GitNotesStorage($this->tempRepoDir);

        // Commit 1 (oldest of the 3): marker causes command to fail without generating coverage.xml
        file_put_contents($this->tempRepoDir . '/fail.txt', 'fail');
        $this->runGit(['add', 'fail.txt']);
        $this->runGit(['commit', '-m', 'Failing commit']);
        $failingSha = $this->runGit(['rev-parse', 'HEAD']);

        // Commit 2: missing note, should be backfilled with 80% coverage
        unlink($this->tempRepoDir . '/fail.txt');
        file_put_contents($this->tempRepoDir . '/metrics.txt', '80');
        $this->runGit(['add', '-A']);
        $this->runGit(['commit', '-m', 'Missing note commit']);
        $missingSha = $this->runGit(['rev-parse', 'HEAD']);

        // Commit 3: already has a note (90%), should be skipped unless --force is passed
        file_put_contents($this->tempRepoDir . '/metrics.txt', '95');
        $this->runGit(['add', 'metrics.txt']);
        $this->runGit(['commit', '-m', 'Existing note commit']);
        $existingSha = $this->runGit(['rev-parse', 'HEAD']);
        $storage->recordNote(new CoverageSummary(new ItemMetrics(100, 90)), $existingSha);

        $this->runGit(['push', '-u', 'origin', 'main']);

        $cmd = <<<'SH'
        if [ -f fail.txt ]; then exit 1; fi
        COV=$(cat metrics.txt)
        cat <<XML > coverage.xml
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage><project><metrics statements="100" coveredstatements="$COV"/></project></coverage>
        XML
        SH;

        $tester = new CommandTester(new BackfillCommand(storage: $storage));
        $exitCode = $tester->execute([
            '--limit' => '3',
            '--branch' => 'main',
            '--command' => $cmd,
            '--push' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Skipping', $display);
        self::assertStringContainsString('Failing commit', $display);
        self::assertStringContainsString('Recorded coverage note on', $display);
        self::assertStringContainsString('Missing note commit', $display);
        self::assertStringContainsString('80.00%', $display);
        self::assertStringContainsString('Pushed refs/notes/coverage to origin.', $display);

        self::assertNull($storage->readNote($failingSha));
        $missingNote = $storage->readNote($missingSha);
        self::assertNotNull($missingNote);
        self::assertSame(80.0, $missingNote->project->percentage);
        // Existing note was untouched (90.0%, not recomputed to 95.0%)
        $existingNote = $storage->readNote($existingSha);
        self::assertNotNull($existingNote);
        self::assertSame(90.0, $existingNote->project->percentage);

        // Running again on the latest 2 commits reports that all already have notes
        $noopExit = $tester->execute([
            '--limit' => '2',
            '--branch' => 'main',
            '--command' => $cmd,
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::SUCCESS, $noopExit);
        self::assertStringContainsString('All inspected commits already have coverage notes.', $tester->getDisplay());

        // Running with --force recomputes existing note (90% -> 95%)
        $forceExit = $tester->execute([
            '--limit' => '1',
            '--branch' => 'main',
            '--command' => $cmd,
            '--force' => true,
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::SUCCESS, $forceExit);
        $recomputedNote = (new GitNotesStorage($this->tempRepoDir))->readNote($existingSha);
        self::assertNotNull($recomputedNote);
        self::assertSame(95.0, $recomputedNote->project->percentage);

        // Verify pushed notes exist in remote by deleting local ref and fetching from origin
        $this->runGit(['update-ref', '-d', 'refs/notes/coverage']);
        $freshStorage = new GitNotesStorage($this->tempRepoDir);
        self::assertNull($freshStorage->readNote($missingSha));
        self::assertTrue($freshStorage->fetchNotes());
        $fetchedNote = (new GitNotesStorage($this->tempRepoDir))->readNote($missingSha);
        self::assertNotNull($fetchedNote);
        self::assertSame(80.0, $fetchedNote->project->percentage);
    }

    public function testFailsWhenBranchDoesNotExist(): void
    {
        $storage = new GitNotesStorage($this->tempRepoDir);
        $tester = new CommandTester(new BackfillCommand(storage: $storage));

        $exitCode = $tester->execute([
            '--branch' => 'non-existent-branch',
            '--no-fetch' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Failed to read git log', $tester->getDisplay());
    }

    /**
     * @param list<string> $args
     */
    private function runGit(array $args, ?string $cwd = null): string
    {
        $process = new Process(['git', ...$args], $cwd ?? $this->tempRepoDir);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
