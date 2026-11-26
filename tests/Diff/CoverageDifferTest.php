<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Tests\Diff;

use PHPUnit\Framework\TestCase;
use Sebb\CoverageNotes\Coverage\CoverageSummary;
use Sebb\CoverageNotes\Coverage\ItemMetrics;
use Sebb\CoverageNotes\Diff\CoverageDiffer;
use Sebb\CoverageNotes\Diff\ItemChangeType;
use Sebb\CoverageNotes\Report\MarkdownFormatter;

final class CoverageDifferTest extends TestCase
{
    public function testDetectsAddedModifiedAndRemovedItems(): void
    {
        $baseline = new CoverageSummary(
            project: new ItemMetrics(statements: 100, covered: 80),
            items: [
                'App\Service\PaymentService' => new ItemMetrics(statements: 50, covered: 40),
                'App\Legacy\OldHelper' => new ItemMetrics(statements: 20, covered: 20),
                'App\Unchanged\StableClass' => new ItemMetrics(statements: 30, covered: 20),
            ],
        );

        $current = new CoverageSummary(
            project: new ItemMetrics(statements: 100, covered: 85),
            items: [
                'App\Service\PaymentService' => new ItemMetrics(statements: 50, covered: 45),
                'App\Validator\CouponValidator' => new ItemMetrics(statements: 20, covered: 20),
                'App\Unchanged\StableClass' => new ItemMetrics(statements: 30, covered: 20),
            ],
        );

        $differ = new CoverageDiffer();
        $diff = $differ->diff($current, $baseline, 'a1b2c3d4e5f6');

        self::assertSame(5.0, $diff->projectPercentageDelta);
        self::assertCount(3, $diff->changedItems);

        self::assertSame(ItemChangeType::Removed, $diff->changedItems['App\Legacy\OldHelper']->type);
        self::assertSame(ItemChangeType::Modified, $diff->changedItems['App\Service\PaymentService']->type);
        self::assertSame(10.0, $diff->changedItems['App\Service\PaymentService']->percentageDelta);
        self::assertSame(ItemChangeType::Added, $diff->changedItems['App\Validator\CouponValidator']->type);

        $markdown = (new MarkdownFormatter())->format($diff);
        self::assertStringContainsString('### 🟢 Coverage: 85.00% (+5.00%)', $markdown);
        self::assertStringContainsString('Up from 80.00% vs `a1b2c3d` · 85/100 statements', $markdown);
        self::assertStringContainsString('| `App\Validator\CouponValidator` | 100.00% | 🆕 New | 20/20 |', $markdown);
        self::assertStringContainsString('| `App\Service\PaymentService` | 90.00% | 🟢 **+10.00%** | 45/50 |', $markdown);
        self::assertStringContainsString('| ~~`App\Legacy\OldHelper`~~ | ~~100.00%~~ | 🗑️ Removed | ~~20/20~~ |', $markdown);
    }
}
