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
        self::assertStringContainsString('**Overall:** **80.00%** (`40/50` statements) — *No baseline coverage note found.*', $markdown);

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
        self::assertStringContainsString('_No class or file coverage changes._', $markdown);
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
        self::assertStringContainsString('*(2 commits behind target)*', $markdown);
        self::assertStringContainsString('| ⚠️ Decreased | `App\Service\DroppedService` | 90.00% | 70.00% | -20.00% | `35/50` |', $markdown);
        self::assertStringContainsString('| ✅ Improved | `App\Service\ImprovedService` | 66.67% | 90.00% | +23.33% | `27/30` |', $markdown);
        self::assertStringContainsString('| 🆕 New | `App\Service\AddedService` | N/A | 90.00% | New | `18/20` |', $markdown);
        self::assertStringContainsString('| 🗑️ Removed | `App\Service\RemovedService` | 100.00% | N/A | Removed | was `20/20` |', $markdown);

        $json = (new JsonFormatter())->format($diff);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('fedcba987654', $decoded['baseline_commit']);
        self::assertSame(2, $decoded['commits_behind_target']);
        self::assertSame(-5.0, $decoded['project_percentage_delta']);
        self::assertIsArray($decoded['changed_items']);
        self::assertCount(4, $decoded['changed_items']);
    }
}
