<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Tests\Git;

use PHPUnit\Framework\TestCase;
use Sebb\CoverageNotes\Command\CompareCommand;
use Sebb\CoverageNotes\Command\RecordCommand;
use Sebb\CoverageNotes\Git\BaselineResolutionStrategy;
use Sebb\CoverageNotes\Git\GitNotesStorage;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class GitNotesIntegrationTest extends TestCase
{
    private string $tempRepoDir;

    protected function setUp(): void
    {
        $this->tempRepoDir = sys_get_temp_dir() . '/coverage-notes-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempRepoDir, 0777, true);

        $this->runGit(['init', '-b', 'main']);
        $this->runGit(['config', 'user.name', 'Test User']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRepoDir)) {
            (new Process(['rm', '-rf', $this->tempRepoDir]))->run();
        }
    }

    public function testRecordAndCompareWithMergeBaseAndWalkBack(): void
    {
        // 1. Commit 1 on main (with coverage note: 80%)
        file_put_contents($this->tempRepoDir . '/file.txt', "commit 1\n");
        $this->runGit(['add', 'file.txt']);
        $this->runGit(['commit', '-m', 'Initial commit on main']);
        $commit1Sha = $this->runGit(['rev-parse', 'HEAD']);

        $clover1 = $this->tempRepoDir . '/coverage-1.xml';
        file_put_contents($clover1, $this->buildCloverXml(100, 80));

        $storage = new GitNotesStorage($this->tempRepoDir);
        $recordCmd = new RecordCommand(storage: $storage);
        $recordTester = new CommandTester($recordCmd);
        $exitCode = $recordTester->execute([
            'clover-file' => $clover1,
            '--workspace' => $this->tempRepoDir,
            '--no-fetch' => true,
        ]);
        self::assertSame(0, $exitCode);

        // 2. Commit 2 on main WITHOUT a coverage note (simulating [skip ci] or docs commit)
        file_put_contents($this->tempRepoDir . '/file.txt', "commit 2\n");
        $this->runGit(['add', 'file.txt']);
        $this->runGit(['commit', '-m', 'Docs commit without coverage note']);

        // 3. Branch off main for a feature PR
        $this->runGit(['checkout', '-b', 'feature/improve-tests']);
        file_put_contents($this->tempRepoDir . '/file.txt', "feature commit\n");
        $this->runGit(['add', 'file.txt']);
        $this->runGit(['commit', '-m', 'Add feature tests']);

        // Verify resolveBaseline walks back from Commit 2 (merge-base) to Commit 1!
        $resolved = $storage->resolveBaseline('main', BaselineResolutionStrategy::MergeBase);
        self::assertNotNull($resolved);
        self::assertSame($commit1Sha, $resolved->commitSha);
        self::assertSame(1, $resolved->commitsBehindTarget);
        self::assertSame(80.0, $resolved->summary->project->percentage);

        // 4. Compare current coverage (85%) against main
        $cloverCurrent = $this->tempRepoDir . '/coverage-current.xml';
        file_put_contents($cloverCurrent, $this->buildCloverXml(100, 85));

        $compareCmd = new CompareCommand(storage: $storage);
        $compareTester = new CommandTester($compareCmd);
        $compareExit = $compareTester->execute([
            'clover-file' => $cloverCurrent,
            '--base' => 'main',
            '--workspace' => $this->tempRepoDir,
            '--no-fetch' => true,
            '--format' => 'markdown',
            '--max-drop' => '0',
        ]);

        self::assertSame(0, $compareExit);
        $display = $compareTester->getDisplay();
        self::assertStringContainsString('80.00% ➔ **85.00%** (`+5.00%`', $display);
        self::assertStringContainsString('*(1 commit behind target)*', $display);

        // 5. Verify --max-drop fails when coverage drops
        $cloverDropped = $this->tempRepoDir . '/coverage-dropped.xml';
        file_put_contents($cloverDropped, $this->buildCloverXml(100, 75));

        $dropExit = $compareTester->execute([
            'clover-file' => $cloverDropped,
            '--base' => 'main',
            '--workspace' => $this->tempRepoDir,
            '--no-fetch' => true,
            '--max-drop' => '2.0',
        ]);
        self::assertSame(1, $dropExit);
    }

    /**
     * @param list<string> $args
     */
    private function runGit(array $args): string
    {
        $process = new Process(['git', ...$args], $this->tempRepoDir);
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
