<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Tests\Report;

use PHPUnit\Framework\TestCase;
use Sebb\CoverageNotes\Coverage\CoverageSummary;
use Sebb\CoverageNotes\Coverage\ItemMetrics;
use Sebb\CoverageNotes\Diff\CoverageDiffer;
use Sebb\CoverageNotes\Report\ConsoleFormatter;
use Sebb\CoverageNotes\Report\JsonFormatter;
use Sebb\CoverageNotes\Report\MarkdownFormatter;
use Symfony\Component\Console\Output\BufferedOutput;

final class FormatterTest extends TestCase
{
    public function testFormattersHandleMissingBaseline(): void
    {
        $current = new CoverageSummary(
            project: new ItemMetrics(statements: 50, covered: 40),
        );

        $diff = (new CoverageDiffer())->diff($current);

        $consoleOutput = new BufferedOutput();
        (new ConsoleFormatter())->render($diff, $consoleOutput);
        self::assertStringContainsString('Coverage: 80.00% (40/50 statements) — no baseline note found', $consoleOutput->fetch());

        $markdown = (new MarkdownFormatter())->format($diff);
        self::assertStringContainsString('### 📊 Coverage: 80.00%', $markdown);
        self::assertStringContainsString('40/50 statements · *No baseline coverage note found*', $markdown);

        $json = (new JsonFormatter())->format($diff);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertNull($decoded['baseline']);
        self::assertNull($decoded['project_percentage_delta']);
    }

    public function testFormattersHandleNoChangedItems(): void
    {
        $summary = new CoverageSummary(
            project: new ItemMetrics(statements: 50, covered: 40),
            items: [
                'App\Service\StableService' => new ItemMetrics(statements: 50, covered: 40),
            ],
        );

        $diff = (new CoverageDiffer())->diff($summary, $summary, 'abc1234def5678');

        $consoleOutput = new BufferedOutput();
        (new ConsoleFormatter())->render($diff, $consoleOutput);
        $consoleText = $consoleOutput->fetch();
        self::assertStringContainsString('Overall Coverage: 80.00% ➔ 80.00% (0.00% vs abc1234 | 40/50 statements)', $consoleText);
        self::assertStringContainsString('No item-level coverage changes.', $consoleText);

        $markdown = (new MarkdownFormatter())->format($diff);
        self::assertStringContainsString('### ⚪ Coverage: 80.00%', $markdown);
        self::assertStringContainsString('No coverage changes vs `abc1234` · 40/50 statements', $markdown);
        self::assertStringNotContainsString('➔', $markdown);
        self::assertDoesNotMatchRegularExpression('/(?<!\d)0\.00%/', $markdown);
        self::assertStringNotContainsString('| Class / File |', $markdown);
    }

    public function testFormattersRenderAddedModifiedAndRemovedItems(): void
    {
        $baseline = new CoverageSummary(
            project: new ItemMetrics(statements: 100, covered: 85),
            items: [
                'App\Service\DroppedService' => new ItemMetrics(statements: 50, covered: 45),
                'App\Service\ImprovedService' => new ItemMetrics(statements: 30, covered: 20),
                'App\Service\RemovedService' => new ItemMetrics(statements: 20, covered: 20),
            ],
        );

        $current = new CoverageSummary(
            project: new ItemMetrics(statements: 100, covered: 80),
            items: [
                'App\Service\AddedService' => new ItemMetrics(statements: 20, covered: 18),
                'App\Service\DroppedService' => new ItemMetrics(statements: 50, covered: 35),
                'App\Service\ImprovedService' => new ItemMetrics(statements: 30, covered: 27),
            ],
        );

        $diff = (new CoverageDiffer())->diff($current, $baseline, 'fedcba987654', commitsBehindTarget: 2);

        $consoleOutput = new BufferedOutput();
        (new ConsoleFormatter())->render($diff, $consoleOutput, commitsBehind: 2);
        $consoleText = $consoleOutput->fetch();

        self::assertStringContainsString('85.00% ➔ 80.00% (-5.00% vs fedcba9 (2 commits behind target) | 80/100 statements)', $consoleText);
        self::assertStringContainsString('NEW', $consoleText);
        self::assertStringContainsString('CHANGED', $consoleText);
        self::assertStringContainsString('REMOVED', $consoleText);
        self::assertStringContainsString('App\Service\AddedService', $consoleText);
        self::assertStringContainsString('App\Service\DroppedService', $consoleText);
        self::assertStringContainsString('was 20/20', $consoleText);

        $markdown = (new MarkdownFormatter())->format($diff, commitsBehind: 2);
        self::assertStringContainsString('### 🔴 Coverage: 80.00% (-5.00%)', $markdown);
        self::assertStringContainsString('Down from 85.00% vs `fedcba9` *(2 commits behind target)* · 80/100 statements', $markdown);
        self::assertStringContainsString('| `App\Service\DroppedService` | 70.00% | 🔴 **-20.00%** | 35/50 |', $markdown);
        self::assertStringContainsString('| `App\Service\AddedService` | 90.00% | 🆕 New | 18/20 |', $markdown);
        self::assertStringContainsString('| `App\Service\ImprovedService` | 90.00% | 🟢 **+23.33%** | 27/30 |', $markdown);
        self::assertStringContainsString('| ~~`App\Service\RemovedService`~~ | ~~100.00%~~ | 🗑️ Removed | ~~20/20~~ |', $markdown);

        $json = (new JsonFormatter())->format($diff);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('fedcba987654', $decoded['baseline_commit']);
        self::assertSame(2, $decoded['commits_behind_target']);
        self::assertSame(-5.0, $decoded['project_percentage_delta']);
        self::assertIsArray($decoded['changed_items']);
        self::assertCount(4, $decoded['changed_items']);
    }

    public function testMarkdownFormatterHandlesUnchangedOverallWithItemChangesAndSortsRows(): void
    {
        $baseline = new CoverageSummary(
            project: new ItemMetrics(statements: 150, covered: 120),
            items: [
                'App\Service\BigDropService' => new ItemMetrics(statements: 50, covered: 45),
                'App\Service\SmallDropService' => new ItemMetrics(statements: 20, covered: 18),
                'App\Service\SmallGainService' => new ItemMetrics(statements: 20, covered: 10),
                'App\Service\BigGainService' => new ItemMetrics(statements: 20, covered: 10),
                'App\Service\FlatService' => new ItemMetrics(statements: 10, covered: 10),
                'App\Service\RemovedService' => new ItemMetrics(statements: 30, covered: 27),
            ],
        );

        $current = new CoverageSummary(
            project: new ItemMetrics(statements: 155, covered: 124),
            items: [
                'App\Service\BigDropService' => new ItemMetrics(statements: 50, covered: 39),
                'App\Service\SmallDropService' => new ItemMetrics(statements: 20, covered: 17),
                'App\Service\AddedService' => new ItemMetrics(statements: 30, covered: 24),
                'App\Service\BigGainService' => new ItemMetrics(statements: 20, covered: 18),
                'App\Service\SmallGainService' => new ItemMetrics(statements: 20, covered: 11),
                'App\Service\FlatService' => new ItemMetrics(statements: 15, covered: 15),
            ],
        );

        $diff = (new CoverageDiffer())->diff($current, $baseline, 'b6c5271abcdef');
        $markdown = (new MarkdownFormatter())->format($diff);

        self::assertStringContainsString('### ⚪ Coverage: 80.00%', $markdown);
        self::assertStringContainsString('Unchanged vs `b6c5271` · 124/155 statements', $markdown);
        self::assertStringNotContainsString('➔', $markdown);
        self::assertDoesNotMatchRegularExpression('/(?<!\d)0\.00%/', $markdown);

        $expectedRows = [
            '| `App\Service\BigDropService` | 78.00% | 🔴 **-12.00%** | 39/50 |',
            '| `App\Service\SmallDropService` | 85.00% | 🔴 **-5.00%** | 17/20 |',
            '| `App\Service\AddedService` | 80.00% | 🆕 New | 24/30 |',
            '| `App\Service\BigGainService` | 90.00% | 🟢 **+40.00%** | 18/20 |',
            '| `App\Service\SmallGainService` | 55.00% | 🟢 **+5.00%** | 11/20 |',
            '| `App\Service\FlatService` | 100.00% | ⚪ Unchanged | 10/10 → 15/15 |',
            '| ~~`App\Service\RemovedService`~~ | ~~90.00%~~ | 🗑️ Removed | ~~27/30~~ |',
        ];

        $previousPos = -1;
        foreach ($expectedRows as $row) {
            $pos = strpos($markdown, $row);
            self::assertNotFalse($pos, sprintf('Expected row not found: %s', $row));
            self::assertGreaterThan($previousPos, $pos);
            $previousPos = $pos;
        }
    }

    public function testMarkdownFormatterEscapesPipesInItemNames(): void
    {
        $baseline = new CoverageSummary(
            project: new ItemMetrics(statements: 10, covered: 8),
            items: [
                'src/old|file.php' => new ItemMetrics(statements: 10, covered: 8),
            ],
        );

        $current = new CoverageSummary(
            project: new ItemMetrics(statements: 20, covered: 18),
            items: [
                'src/foo|bar.php' => new ItemMetrics(statements: 20, covered: 18),
            ],
        );

        $diff = (new CoverageDiffer())->diff($current, $baseline, 'abcdef123456');
        $markdown = (new MarkdownFormatter())->format($diff);

        self::assertStringContainsString('| `src/foo\|bar.php` | 90.00% | 🆕 New | 18/20 |', $markdown);
        self::assertStringContainsString('| ~~`src/old\|file.php`~~ | ~~80.00%~~ | 🗑️ Removed | ~~8/10~~ |', $markdown);
    }
}
