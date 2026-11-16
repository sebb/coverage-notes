<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Coverage;

use InvalidArgumentException;

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

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['project']) || !is_array($decoded['project'])) {
            throw new InvalidArgumentException('Invalid coverage note JSON: missing "project" key.');
        }

        $version = isset($decoded['version']) ? (int) $decoded['version'] : self::CURRENT_VERSION;
        $project = ItemMetrics::fromArray($decoded['project']);

        $items = [];
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            foreach ($decoded['items'] as $name => $itemData) {
                if (is_string($name) && is_array($itemData)) {
                    $items[$name] = ItemMetrics::fromArray($itemData);
                }
            }
        }

        ksort($items);

        return new self(
            project: $project,
            items: $items,
            version: $version,
        );
    }

    /**
     * Serializes the summary to a Git-delta-friendly JSON format where each item is on its own single line.
     */
    public function toJson(): string
    {
        $sortedItems = $this->items;
        ksort($sortedItems);

        $projectJson = json_encode($this->project->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        if ($sortedItems === []) {
            return <<<JSON
            {
                "version": {$this->version},
                "project": {$projectJson},
                "items": {}
            }

            JSON;
        }

        $itemLines = [];
        foreach ($sortedItems as $name => $metrics) {
            $encodedName = json_encode($name, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $encodedMetrics = json_encode($metrics->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $itemLines[] = sprintf('        %s: %s', $encodedName, $encodedMetrics);
        }

        $itemsBlock = implode(",\n", $itemLines);

        return <<<JSON
        {
            "version": {$this->version},
            "project": {$projectJson},
            "items": {
        {$itemsBlock}
            }
        }

        JSON;
    }
}
