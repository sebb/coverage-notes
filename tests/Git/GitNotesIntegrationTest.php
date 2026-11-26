<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Tests\Git;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sebb\CoverageNotes\Command\CompareCommand;
use Sebb\CoverageNotes\Command\RecordCommand;
use Sebb\CoverageNotes\Git\BaselineResolutionStrategy;
use Sebb\CoverageNotes\Git\GitNotesStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class GitNotesIntegrationTest extends TestCase
{
    private string $tempRootDir;
    private string $tempRemoteDir;
    private string $tempRepoDir;

    protected function setUp(): void
    {
        $this->tempRootDir = sys_get_temp_dir() . '/coverage-notes-test-' . bin2hex(random_bytes(6));
        $this->tempRemoteDir = $this->tempRootDir . '/remote.git';
        $this->tempRepoDir = $this->tempRootDir . '/repo';

        mkdir($this->tempRemoteDir, 0777, true);
        mkdir($this->tempRepoDir, 0777, true);

        $this->runGit(['init', '--bare'], $this->tempRemoteDir);
        $this->runGit(['init', '-b', 'main']);
        $this->runGit(['config', 'user.name', 'Test User']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
        $this->runGit(['remote', 'add', 'origin', $this->tempRemoteDir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRootDir)) {
            (new Process(['rm', '-rf', $this->tempRootDir]))->run();
        }
    }

    public function testRecordPushFetchAndCompareAcrossRemoteAndBranches(): void
    {
        // 1. Commit 1 on main (with coverage note: 80%) and push both branch and note to origin
        file_put_contents($this->tempRepoDir . '/file.txt', "commit 1\n");
        $this->runGit(['add', 'file.txt']);
        $this->runGit(['commit', '-m', 'Initial commit on main']);
        $commit1Sha = $this->runGit(['rev-parse', 'HEAD']);
        $this->runGit(['push', '-u', 'origin', 'main']);

        $clover1 = $this->tempRepoDir . '/coverage-1.xml';
        file_put_contents($clover1, $this->buildCloverXml(100, 80));

        $storage = new GitNotesStorage($this->tempRepoDir);
        $recordTester = new CommandTester(new RecordCommand(storage: $storage));
        $exitCode = $recordTester->execute([
            'clover-file' => $clover1,
            '--workspace' => $this->tempRepoDir,
            '--push' => true,
        ]);
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Pushed refs/notes/coverage to origin.', $recordTester->getDisplay());

        // 2. Commit 2 on main with another note pushed (exercises fetch + merge -s ours on remote notes)
        file_put_contents($this->tempRepoDir . '/file.txt', "commit 2\n");
        $this->runGit(['add', 'file.txt']);
        $this->runGit(['commit', '-m', 'Second commit on main']);
        $commit2Sha = $this->runGit(['rev-parse', 'HEAD']);
        $this->runGit(['push', 'origin', 'main']);

        $clover2 = $this->tempRepoDir . '/coverage-2.xml';
        file_put_contents($clover2, $this->buildCloverXml(100, 82));

        $exitCode2 = $recordTester->execute([
            'clover-file' => $clover2,
            '--workspace' => $this->tempRepoDir,
            '--push' => true,
        ]);
        self::assertSame(Command::SUCCESS, $exitCode2);

        // 3. Commit 3 on main WITHOUT a coverage note (simulating [skip ci] or docs commit)
        file_put_contents($this->tempRepoDir . '/file.txt', "commit 3\n");
        $this->runGit(['add', 'file.txt']);
        $this->runGit(['commit', '-m', 'Docs commit without coverage note']);
        $this->runGit(['push', 'origin', 'main']);

        // 4. Branch off main for a feature PR and verify walk-back + direct strategy
        $this->runGit(['checkout', '-b', 'feature/improve-tests']);
        file_put_contents($this->tempRepoDir . '/file.txt', "feature commit\n");
        $this->runGit(['add', 'file.txt']);
        $this->runGit(['commit', '-m', 'Add feature tests']);

        // Delete local notes ref so CompareCommand must fetch them from origin
        $this->runGit(['update-ref', '-d', 'refs/notes/coverage']);

        // Without maxWalkBack, Commit 3 has no note so resolveBaseline(maxWalkBack: 0) returns null
        self::assertTrue($storage->fetchNotes());
        self::assertNull($storage->resolveBaseline('origin/main', BaselineResolutionStrategy::MergeBase, maxWalkBack: 0));

        // Verify resolveBaseline walks back from Commit 3 (merge-base) to Commit 2
        $resolved = $storage->resolveBaseline('origin/main', BaselineResolutionStrategy::MergeBase);
        self::assertNotNull($resolved);
        self::assertSame($commit2Sha, $resolved->commitSha);
        self::assertSame(1, $resolved->commitsBehindTarget);
        self::assertSame(82.0, $resolved->summary->project->percentage);

        // Verify direct strategy pointing directly at Commit 1
        $resolvedDirect = $storage->resolveBaseline($commit1Sha, BaselineResolutionStrategy::Direct);
        self::assertNotNull($resolvedDirect);
        self::assertSame($commit1Sha, $resolvedDirect->commitSha);
        self::assertSame(0, $resolvedDirect->commitsBehindTarget);
        self::assertSame(80.0, $resolvedDirect->summary->project->percentage);

        // 5. Compare current coverage (85%) against origin/main in markdown, console, and json formats
        $cloverCurrent = $this->tempRepoDir . '/coverage-current.xml';
        file_put_contents($cloverCurrent, $this->buildCloverXml(100, 85));

        $compareTester = new CommandTester(new CompareCommand(storage: $storage));
        $markdownExit = $compareTester->execute([
            'clover-file' => $cloverCurrent,
            '--base' => 'origin/main',
            '--workspace' => $this->tempRepoDir,
            '--format' => 'markdown',
            '--max-drop' => '0',
            '--fail-under' => '80',
        ]);
        self::assertSame(Command::SUCCESS, $markdownExit);
        $markdownDisplay = $compareTester->getDisplay();
        self::assertStringContainsString('### 🟢 Coverage: 85.00% (+3.00%)', $markdownDisplay);
        self::assertStringContainsString('Up from 82.00% vs `', $markdownDisplay);
        self::assertStringContainsString('*(1 commit behind target)*', $markdownDisplay);

        $consoleExit = $compareTester->execute([
            'clover-file' => $cloverCurrent,
            '--base' => 'origin/main',
            '--workspace' => $this->tempRepoDir,
            '--no-fetch' => true,
            '--format' => 'console',
        ]);
        self::assertSame(Command::SUCCESS, $consoleExit);
        self::assertStringContainsString('Overall Coverage: 82.00% ➔ 85.00%', $compareTester->getDisplay());

        $jsonExit = $compareTester->execute([
            'clover-file' => $cloverCurrent,
            '--base' => $commit1Sha,
            '--strategy' => 'direct',
            '--workspace' => $this->tempRepoDir,
            '--no-fetch' => true,
            '--format' => 'json',
        ]);
        self::assertSame(Command::SUCCESS, $jsonExit);
        $decodedJson = json_decode($compareTester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decodedJson);
        self::assertSame($commit1Sha, $decodedJson['baseline_commit']);
        self::assertSame(5.0, $decodedJson['project_percentage_delta']);

        // 6. Verify --max-drop and --fail-under failures
        $cloverDropped = $this->tempRepoDir . '/coverage-dropped.xml';
        file_put_contents($cloverDropped, $this->buildCloverXml(100, 75));

        $dropExit = $compareTester->execute([
            'clover-file' => $cloverDropped,
            '--base' => 'origin/main',
            '--workspace' => $this->tempRepoDir,
            '--no-fetch' => true,
            '--max-drop' => '2.0',
        ]);
        self::assertSame(Command::FAILURE, $dropExit);
        self::assertStringContainsString('exceeds the maximum allowed drop', $compareTester->getDisplay());

        $failUnderExit = $compareTester->execute([
            'clover-file' => $cloverDropped,
            '--base' => 'origin/main',
            '--workspace' => $this->tempRepoDir,
            '--no-fetch' => true,
            '--fail-under' => '80.0',
        ]);
        self::assertSame(Command::FAILURE, $failUnderExit);
        self::assertStringContainsString('below the required minimum threshold', $compareTester->getDisplay());
    }

    public function testRecordAndCompareErrorPathsAndDryRun(): void
    {
        file_put_contents($this->tempRepoDir . '/file.txt', "init\n");
        $this->runGit(['add', 'file.txt']);
        $this->runGit(['commit', '-m', 'Init']);

        $clover = $this->tempRepoDir . '/coverage.xml';
        file_put_contents($clover, $this->buildCloverXml(50, 40));

        $storage = new GitNotesStorage($this->tempRepoDir);
        $recordTester = new CommandTester(new RecordCommand(storage: $storage));

        // Dry run outputs JSON without touching git notes
        $dryExit = $recordTester->execute([
            'clover-file' => $clover,
            '--workspace' => $this->tempRepoDir,
            '--dry-run' => true,
        ]);
        self::assertSame(Command::SUCCESS, $dryExit);
        self::assertStringContainsString('"percentage": 80.0', $recordTester->getDisplay());
        self::assertNull($storage->readNote('HEAD'));

        // Missing clover file fails cleanly
        $missingRecordExit = $recordTester->execute([
            'clover-file' => $this->tempRepoDir . '/missing.xml',
            '--workspace' => $this->tempRepoDir,
        ]);
        self::assertSame(Command::FAILURE, $missingRecordExit);

        // Invalid commit reference fails cleanly
        $badCommitExit = $recordTester->execute([
            'clover-file' => $clover,
            '--commit' => 'nonexistent-ref',
            '--workspace' => $this->tempRepoDir,
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::FAILURE, $badCommitExit);
        self::assertStringContainsString('Could not resolve Git reference', $recordTester->getDisplay());

        // Compare with missing clover file fails cleanly
        $compareTester = new CommandTester(new CompareCommand(storage: $storage));
        $missingCompareExit = $compareTester->execute([
            'clover-file' => $this->tempRepoDir . '/missing.xml',
            '--workspace' => $this->tempRepoDir,
        ]);
        self::assertSame(Command::FAILURE, $missingCompareExit);

        // Baseline resolution on nonexistent ref returns null
        self::assertNull($storage->resolveBaseline('nonexistent-branch', BaselineResolutionStrategy::Direct));

        // Pushing to nonexistent remote throws RuntimeException
        $this->expectException(RuntimeException::class);
        $storage->pushNotes(remote: 'nonexistent-remote');
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

    private function buildCloverXml(int $statements, int $covered): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage generated="1700000000">
          <project timestamp="1700000000">
            <file name="{$this->tempRepoDir}/src/Service/DemoService.php">
              <class name="App\\Service\\DemoService" namespace="App\\Service">
                <metrics statements="{$statements}" coveredstatements="{$covered}"/>
              </class>
              <metrics statements="{$statements}" coveredstatements="{$covered}"/>
            </file>
            <metrics statements="{$statements}" coveredstatements="{$covered}"/>
          </project>
        </coverage>
        XML;
    }
}
