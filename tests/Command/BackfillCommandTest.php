<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Tests\Command;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sebb\CoverageNotes\Command\BackfillCommand;
use Sebb\CoverageNotes\Coverage\CoverageSummary;
use Sebb\CoverageNotes\Coverage\ItemMetrics;
use Sebb\CoverageNotes\Git\BaselineResolutionStrategy;
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

        // Create an untracked vendor directory and a nested subdirectory to test getRepositoryRoot()
        mkdir($this->tempRepoDir . '/vendor', 0777, true);
        mkdir($this->tempRepoDir . '/src/Sub', 0777, true);
        file_put_contents(
            $this->tempRepoDir . '/vendor/autoload.php',
            "<?php\n\nrequire_once __DIR__ . '/../src/Calculator.php';\n",
        );
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

    public function testBackfillsFromSubdirectoryTracksClassChangesAndHandlesErrors(): void
    {
        $subDir = $this->tempRepoDir . '/src/Sub';
        $subDirStorage = new GitNotesStorage($subDir);
        $storage = new GitNotesStorage($this->tempRepoDir);

        // Commit 1: fails the test command without generating coverage.xml
        file_put_contents($this->tempRepoDir . '/state.txt', 'fail');
        $this->runGit(['add', 'state.txt']);
        $this->runGit(['commit', '-m', 'Failing commit']);
        $failingSha = $this->runGit(['rev-parse', 'HEAD']);

        // Commit 2: generates invalid XML (exercises exception handling during parsing)
        file_put_contents($this->tempRepoDir . '/state.txt', 'invalid-xml');
        $this->runGit(['add', 'state.txt']);
        $this->runGit(['commit', '-m', 'Invalid XML commit']);
        $invalidXmlSha = $this->runGit(['rev-parse', 'HEAD']);

        // Commit 3: missing note, Calculator has 50% coverage (10/20 statements)
        file_put_contents($this->tempRepoDir . '/state.txt', 'run');
        file_put_contents($this->tempRepoDir . '/src/Calculator.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Service;

        final class Calculator
        {
            public static function covered(): int
            {
                return 10;
            }
        }
        PHP);
        $this->runGit(['add', 'state.txt', 'src/Calculator.php']);
        $this->runGit(['commit', '-m', 'Calculator at 50 percent']);
        $calc50Sha = $this->runGit(['rev-parse', 'HEAD']);

        // Commit 4: already has a pre-existing 90% note, while working tree state is 20/20 (100%)
        file_put_contents($this->tempRepoDir . '/src/Calculator.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Service;

        final class Calculator
        {
            public static function covered(): int
            {
                return 20;
            }
        }
        PHP);
        $this->runGit(['add', 'src/Calculator.php']);
        $this->runGit(['commit', '-m', 'Calculator at 100 percent']);
        $calc100Sha = $this->runGit(['rev-parse', 'HEAD']);
        $storage->recordNote(new CoverageSummary(new ItemMetrics(20, 18)), $calc100Sha);

        $this->runGit(['push', '-u', 'origin', 'main']);

        $cmd = <<<'SH'
        STATE=$(cat state.txt)
        if [ "$STATE" = "fail" ]; then exit 1; fi
        if [ ! -d vendor ] || [ -L vendor ]; then exit 1; fi
        if [ "$STATE" = "invalid-xml" ]; then
          echo "not-xml" > coverage.xml
          exit 0
        fi
        COVERED=$(php -r 'require "vendor/autoload.php"; echo \App\Service\Calculator::covered();')
        cat <<XML > coverage.xml
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage>
          <project>
            <file name="$(pwd)/src/Calculator.php">
              <class name="App\\Service\\Calculator"><metrics statements="20" coveredstatements="$COVERED"/></class>
              <metrics statements="20" coveredstatements="$COVERED"/>
            </file>
            <metrics statements="20" coveredstatements="$COVERED"/>
          </project>
        </coverage>
        XML
        SH;

        // Run from subdirectory to confirm repository root resolution locates <repo-root>/vendor
        $tester = new CommandTester(new BackfillCommand(storage: $subDirStorage));
        $exitCode = $tester->execute([
            '--limit' => '4',
            '--branch' => 'main',
            '--command' => $cmd,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Skipping', $display);
        self::assertStringContainsString('Failing commit', $display);
        self::assertStringContainsString('Invalid XML commit', $display);
        self::assertStringContainsString('Calculator at 50 percent', $display);
        self::assertStringContainsString('50.00%', $display);

        self::assertNull($storage->readNote($failingSha));
        self::assertNull($storage->readNote($invalidXmlSha));

        $calc50Note = $storage->readNote($calc50Sha);
        self::assertNotNull($calc50Note);
        self::assertSame(50.0, $calc50Note->project->percentage);
        self::assertSame(50.0, $calc50Note->items['App\Service\Calculator']->percentage);

        // Pre-existing note was preserved (90.0%) when --force is not passed
        $calc100Note = $storage->readNote($calc100Sha);
        self::assertNotNull($calc100Note);
        self::assertSame(90.0, $calc100Note->project->percentage);

        // Running again with --push on the latest 2 commits reports that all already have notes and pushes existing notes
        $noopExit = $tester->execute([
            '--limit' => '2',
            '--branch' => 'main',
            '--command' => $cmd,
            '--no-fetch' => true,
            '--push' => true,
        ]);
        self::assertSame(Command::SUCCESS, $noopExit);
        self::assertStringContainsString('All inspected commits already have coverage notes.', $tester->getDisplay());
        self::assertStringContainsString('Pushed refs/notes/coverage to origin.', $tester->getDisplay());

        // Verify pushed notes exist in remote by deleting local ref and fetching from origin
        $this->runGit(['update-ref', '-d', 'refs/notes/coverage']);
        $freshStorage = new GitNotesStorage($this->tempRepoDir);
        self::assertNull($freshStorage->readNote($calc50Sha));
        self::assertTrue($freshStorage->fetchNotes());
        $fetchedNote = (new GitNotesStorage($this->tempRepoDir))->readNote($calc50Sha);
        self::assertNotNull($fetchedNote);
        self::assertSame(50.0, $fetchedNote->project->percentage);

        // Corrupted/non-JSON note is treated as missing by readNote() and overwritten by backfill
        $this->runGit(['notes', '--ref=coverage', 'add', '-f', '-m', 'corrupted-json', $calc50Sha]);
        self::assertNull((new GitNotesStorage($this->tempRepoDir))->readNote($calc50Sha));
        $repairExit = $tester->execute([
            '--limit' => '2',
            '--branch' => 'main',
            '--command' => $cmd,
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::SUCCESS, $repairExit);
        $repairedNote = (new GitNotesStorage($this->tempRepoDir))->readNote($calc50Sha);
        self::assertNotNull($repairedNote);
        self::assertSame(50.0, $repairedNote->project->percentage);

        // Running with --force recomputes Calculator at 100% (18/20 -> 20/20) without reading existing notes
        $forceExit = $tester->execute([
            '--limit' => '1',
            '--branch' => 'main',
            '--command' => $cmd,
            '--force' => true,
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::SUCCESS, $forceExit);
        $recomputedNote = (new GitNotesStorage($this->tempRepoDir))->readNote($calc100Sha);
        self::assertNotNull($recomputedNote);
        self::assertSame(100.0, $recomputedNote->project->percentage);
        self::assertSame(100.0, $recomputedNote->items['App\Service\Calculator']->percentage);

        // Verify push failure returns Command::FAILURE
        $pushFailExit = $tester->execute([
            '--limit' => '1',
            '--branch' => 'main',
            '--command' => $cmd,
            '--force' => true,
            '--no-fetch' => true,
            '--push' => true,
            '--remote' => 'nonexistent-remote',
        ]);
        self::assertSame(Command::FAILURE, $pushFailExit);
        self::assertStringContainsString('Failed to push git notes ref', $tester->getDisplay());
    }

    public function testAbortsWhenFetchFailsAndHandlesInvalidBranchAndWorktreeErrors(): void
    {
        $storage = new GitNotesStorage($this->tempRepoDir);
        $tester = new CommandTester(new BackfillCommand(storage: $storage));

        // Fetch failure against nonexistent remote aborts before inspecting history
        $fetchFailExit = $tester->execute([
            '--remote' => 'nonexistent-remote',
        ]);
        self::assertSame(Command::FAILURE, $fetchFailExit);
        self::assertStringContainsString('Failed to synchronize coverage notes from remote "nonexistent-remote".', $tester->getDisplay());

        // Invalid branch fails cleanly
        $invalidBranchExit = $tester->execute([
            '--branch' => 'non-existent-branch',
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::FAILURE, $invalidBranchExit);
        self::assertStringContainsString('Failed to read git log', $tester->getDisplay());

        // Non-git directory falls back cleanly in getRepositoryRoot() and removeWorktree() cleans up orphan directories
        $nonGitDir = $this->tempRootDir . '/non-git';
        mkdir($nonGitDir, 0777, true);
        self::assertSame($nonGitDir, (new GitNotesStorage($nonGitDir))->getRepositoryRoot());
        $storage->removeWorktree($nonGitDir);
        self::assertDirectoryDoesNotExist($nonGitDir);

        // Full refs/notes/... prefix is handled cleanly by normalizeNotesRef(), and missing notes resolve to null
        self::assertTrue($storage->fetchNotes('refs/notes/coverage'));
        self::assertNull($storage->resolveBaseline('HEAD', BaselineResolutionStrategy::MergeBase));

        // Adding a worktree for an invalid ref throws RuntimeException
        $this->expectException(RuntimeException::class);
        $storage->addWorktree($this->tempRootDir . '/bad-worktree', 'nonexistent-commit');
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
