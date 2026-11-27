<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Tests\Command;

use PHPUnit\Framework\TestCase;
use Sebb\CoverageNotes\Command\HistoryCommand;
use Sebb\CoverageNotes\Coverage\CoverageSummary;
use Sebb\CoverageNotes\Coverage\ItemMetrics;
use Sebb\CoverageNotes\Git\GitNotesStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class HistoryCommandTest extends TestCase
{
    private string $tempRepoDir;

    protected function setUp(): void
    {
        $this->tempRepoDir = sys_get_temp_dir() . '/coverage-notes-history-' . bin2hex(random_bytes(6));
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

    public function testDisplaysCoverageHistoryAndDeltas(): void
    {
        $storage = new GitNotesStorage($this->tempRepoDir);

        // Commit 1: 80%
        $this->commitFile('step1.txt', 'First commit', $storage, new CoverageSummary(new ItemMetrics(100, 80)));
        // Commit 2: 80% (0.00% delta)
        $this->commitFile('step2.txt', 'Unchanged coverage commit', $storage, new CoverageSummary(new ItemMetrics(100, 80)));
        // Commit 3: 75% (negative delta)
        $this->commitFile('step3.txt', 'Dropped coverage commit', $storage, new CoverageSummary(new ItemMetrics(100, 75)));
        // Commit 4: no note (N/A)
        $this->commitFile('step4.txt', 'Docs commit without note', $storage, null);
        // Commit 5: 85% (positive delta vs commit 3)
        $this->commitFile('step5.txt', 'Improved coverage commit', $storage, new CoverageSummary(new ItemMetrics(100, 85)));

        $tester = new CommandTester(new HistoryCommand($storage));
        $exitCode = $tester->execute([
            '--limit' => '10',
            '--branch' => 'main',
            '--no-fetch' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Improved coverage commit', $display);
        self::assertStringContainsString('85.00%', $display);
        self::assertStringContainsString('+10.00%', $display);
        self::assertStringContainsString('Docs commit without note', $display);
        self::assertStringContainsString('N/A', $display);
        self::assertStringContainsString('Dropped coverage commit', $display);
        self::assertStringContainsString('-5.00%', $display);
        self::assertStringContainsString('Unchanged coverage commit', $display);
        self::assertStringContainsString('0.00%', $display);
        self::assertStringContainsString('First commit', $display);
    }

    public function testFailsWhenBranchDoesNotExist(): void
    {
        $storage = new GitNotesStorage($this->tempRepoDir);
        $tester = new CommandTester(new HistoryCommand($storage));

        $exitCode = $tester->execute([
            '--branch' => 'non-existent-branch',
            '--no-fetch' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Failed to read git log', $tester->getDisplay());
    }

    public function testDisplaysCommitRangeWithBaselineDeltaAndNoDefaultLimit(): void
    {
        $storage = new GitNotesStorage($this->tempRepoDir);

        $this->commitFile('c01.txt', 'Base commit 70', $storage, new CoverageSummary(new ItemMetrics(100, 70)));
        $this->runGit(['tag', 'v0.1.0']);

        $this->commitFile('c02.txt', 'Range commit 02 no note', $storage, null);
        $this->commitFile('c03.txt', 'Range commit 03', $storage, new CoverageSummary(new ItemMetrics(100, 75)));

        for ($i = 4; $i <= 11; ++$i) {
            $covered = 75 + (($i - 3) * 2);
            $this->commitFile(
                sprintf('c%02d.txt', $i),
                sprintf('Range commit %02d', $i),
                $storage,
                new CoverageSummary(new ItemMetrics(100, $covered)),
            );
        }

        // C12: 95% (+4.00% over C11's 91%)
        $this->commitFile('c12.txt', 'Range commit 12', $storage, new CoverageSummary(new ItemMetrics(100, 95)));
        $this->runGit(['tag', 'v0.2.0']);

        $tester = new CommandTester(new HistoryCommand($storage));

        // 11 commits in v0.1.0..v0.2.0 should all appear without a --limit cap
        $exitCode = $tester->execute([
            'range' => 'v0.1.0..v0.2.0',
            '--no-fetch' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString('Base commit 70', $display);
        self::assertStringContainsString('Range commit 02 no note', $display);
        self::assertStringContainsString('Range commit 03', $display);
        self::assertStringContainsString('+5.00%', $display);
        self::assertStringContainsString('Range commit 12', $display);
        self::assertStringContainsString('+4.00%', $display);

        // Explicit --limit on a range caps displayed commits while still resolving baseline delta for the oldest row
        $limitedExit = $tester->execute([
            'range' => 'v0.1.0..v0.2.0',
            '--limit' => '2',
            '--no-fetch' => true,
        ]);

        self::assertSame(Command::SUCCESS, $limitedExit);
        $limitedDisplay = $tester->getDisplay();

        self::assertStringContainsString('Range commit 12', $limitedDisplay);
        self::assertStringContainsString('Range commit 11', $limitedDisplay);
        self::assertStringNotContainsString('Range commit 10', $limitedDisplay);
        self::assertStringContainsString('+4.00%', $limitedDisplay);
        self::assertStringContainsString('+2.00%', $limitedDisplay);

        // Non-linear branch range main..feature compares against feature's parent (95%), not main's tip (90%)
        $this->runGit(['checkout', '-b', 'feature']);
        $this->commitFile('feature.txt', 'Feature branch commit', $storage, new CoverageSummary(new ItemMetrics(100, 99)));
        $this->runGit(['checkout', 'main']);
        $this->commitFile('main-advance.txt', 'Main branch advance', $storage, new CoverageSummary(new ItemMetrics(100, 90)));

        $branchRangeExit = $tester->execute([
            'range' => 'main..feature',
            '--no-fetch' => true,
        ]);

        self::assertSame(Command::SUCCESS, $branchRangeExit);
        $branchRangeDisplay = $tester->getDisplay();

        self::assertStringContainsString('Feature branch commit', $branchRangeDisplay);
        self::assertStringNotContainsString('Main branch advance', $branchRangeDisplay);
        self::assertStringNotContainsString('Range commit 12', $branchRangeDisplay);
        self::assertStringContainsString('+4.00%', $branchRangeDisplay);
        self::assertStringNotContainsString('+9.00%', $branchRangeDisplay);
    }

    public function testHandlesEmptyCommitRangePositionalSingleRefAndInvalidArguments(): void
    {
        $storage = new GitNotesStorage($this->tempRepoDir);

        for ($i = 1; $i <= 12; ++$i) {
            $this->commitFile(
                sprintf('item%02d.txt', $i),
                sprintf('Commit %02d', $i),
                $storage,
                new CoverageSummary(new ItemMetrics(100, 70 + $i)),
            );
        }

        $tester = new CommandTester(new HistoryCommand($storage));

        $emptyExit = $tester->execute([
            'range' => 'HEAD..HEAD',
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::SUCCESS, $emptyExit);
        self::assertStringContainsString('No commits found.', $tester->getDisplay());

        // Single positional ref still defaults to 10 commits
        $singleRefExit = $tester->execute([
            'range' => 'main',
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::SUCCESS, $singleRefExit);
        $singleRefDisplay = $tester->getDisplay();
        self::assertStringContainsString('Commit 12', $singleRefDisplay);
        self::assertStringContainsString('Commit 03', $singleRefDisplay);
        self::assertStringNotContainsString('Commit 02', $singleRefDisplay);
        self::assertStringNotContainsString('Commit 01', $singleRefDisplay);

        $conflictExit = $tester->execute([
            'range' => 'HEAD~1..HEAD',
            '--branch' => 'main',
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::FAILURE, $conflictExit);
        self::assertStringContainsString('Cannot specify both a commit range argument and the --branch option.', $tester->getDisplay());

        $optionLikeExit = $tester->execute([
            'range' => '--all',
            '--no-fetch' => true,
        ]);
        self::assertSame(Command::FAILURE, $optionLikeExit);
        self::assertStringContainsString('Failed to read git log', $tester->getDisplay());
    }

    private function commitFile(string $filename, string $message, GitNotesStorage $storage, ?CoverageSummary $summary): void
    {
        file_put_contents($this->tempRepoDir . '/' . $filename, $message . "\n");
        $this->runGit(['add', $filename]);
        $this->runGit(['commit', '-m', $message]);

        if ($summary !== null) {
            $storage->recordNote($summary);
        }
    }

    /**
     * @param list<string> $args
     */
    private function runGit(array $args): void
    {
        $process = new Process(['git', ...$args], $this->tempRepoDir);
        $process->mustRun();
    }
}
