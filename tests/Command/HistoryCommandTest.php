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
