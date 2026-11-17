<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Coverage;

final readonly class CoverageSummary
{
    public const int CURRENT_VERSION = 1;

    /**
     * @param array<string, ItemMetrics> $items Sorted map of class FQCN or relative file path to metrics
     */
    public function __construct(
        public ItemMetrics $project,
        public array $items = [],
        public int $version = self::CURRENT_VERSION,
    ) {
    }
}
