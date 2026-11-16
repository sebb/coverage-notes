<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Coverage;

use InvalidArgumentException;
use SimpleXMLElement;

final readonly class CloverParser
{
    public function parseFile(string $cloverFilePath, ?string $workspaceRoot = null): CoverageSummary
    {
        if (!is_file($cloverFilePath) || !is_readable($cloverFilePath)) {
            throw new InvalidArgumentException(sprintf('Clover XML file "%s" does not exist or is not readable.', $cloverFilePath));
        }

        $contents = file_get_contents($cloverFilePath);
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Failed to read Clover XML file "%s".', $cloverFilePath));
        }

        return $this->parseXml($contents, $workspaceRoot);
    }

    public function parseXml(string $xmlContent, ?string $workspaceRoot = null): CoverageSummary
    {
        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($xmlContent);
            if (!$xml instanceof SimpleXMLElement) {
                throw new InvalidArgumentException('Invalid Clover XML content.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }

        if (!isset($xml->project->metrics)) {
            throw new InvalidArgumentException('Clover XML is missing <project><metrics> element.');
        }

        $projectMetricsNode = $xml->project->metrics;
        $project = new ItemMetrics(
            statements: (int) $projectMetricsNode['statements'],
            covered: (int) $projectMetricsNode['coveredstatements'],
        );

        $normalizedRoot = $workspaceRoot !== null && $workspaceRoot !== ''
            ? rtrim(str_replace('\\', '/', $workspaceRoot), '/') . '/'
            : null;

        $items = [];
        $fileNodes = $xml->xpath('//file') ?: [];

        foreach ($fileNodes as $fileNode) {
            if (!isset($fileNode->metrics)) {
                continue;
            }

            $fm = $fileNode->metrics;
            $statements = (int) $fm['statements'];
            $covered = (int) $fm['coveredstatements'];

            if ($statements === 0) {
                continue;
            }

            $key = $this->resolveItemKey($fileNode, $normalizedRoot);

            if (isset($items[$key])) {
                $existing = $items[$key];
                $items[$key] = new ItemMetrics(
                    statements: $existing->statements + $statements,
                    covered: $existing->covered + $covered,
                );
            } else {
                $items[$key] = new ItemMetrics(
                    statements: $statements,
                    covered: $covered,
                );
            }
        }

        ksort($items);

        return new CoverageSummary(
            project: $project,
            items: $items,
        );
    }

    private function resolveItemKey(SimpleXMLElement $fileNode, ?string $normalizedRoot): string
    {
        if (count($fileNode->class) === 1) {
            $className = trim((string) $fileNode->class[0]['name']);
            if ($className !== '') {
                return ltrim($className, '\\');
            }
        }

        $filePath = str_replace('\\', '/', (string) $fileNode['name']);
        if ($normalizedRoot !== null && str_starts_with($filePath, $normalizedRoot)) {
            return substr($filePath, strlen($normalizedRoot));
        }

        return $filePath;
    }
}
