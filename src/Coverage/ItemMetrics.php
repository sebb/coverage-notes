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

    /**
     * @param array{statements: int, covered: int, percentage?: float} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            statements: (int) $data['statements'],
            covered: (int) $data['covered'],
            percentage: isset($data['percentage']) ? (float) $data['percentage'] : null,
        );
    }

    /**
     * @return array{statements: int, covered: int, percentage: float}
     */
    public function toArray(): array
    {
        return [
            'statements' => $this->statements,
            'covered' => $this->covered,
            'percentage' => $this->percentage,
        ];
    }
}
