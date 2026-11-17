<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Coverage;

final readonly class ItemMetrics
{
    public float $percentage;

    public function __construct(
        public int $statements,
        public int $covered,
        ?float $percentage = null,
    ) {
        $this->percentage = $percentage ?? self::calculatePercentage($covered, $statements);
    }

    public static function calculatePercentage(int $covered, int $statements): float
    {
        if ($statements <= 0) {
            return 100.0;
        }

        return round(($covered / $statements) * 100, 2);
    }
}
