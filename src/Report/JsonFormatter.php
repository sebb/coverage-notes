<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Report;

use Sebb\CoverageNotes\Diff\CoverageDiff;
use Sebb\CoverageNotes\Serializer\CoverageSerializer;

final readonly class JsonFormatter
{
    public function __construct(
        private CoverageSerializer $serializer = new CoverageSerializer(),
    ) {
    }

    public function format(CoverageDiff $diff): string
    {
        return $this->serializer->serializeDiff($diff);
    }
}
