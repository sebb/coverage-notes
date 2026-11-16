<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Report;

use Sebb\CoverageNotes\Diff\CoverageDiff;

final readonly class JsonFormatter
{
    public function format(CoverageDiff $diff, int $commitsBehind = 0): string
    {
        $payload = $diff->toArray();
        $payload['commits_behind_target'] = $commitsBehind;

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ) . "\n";
    }
}
