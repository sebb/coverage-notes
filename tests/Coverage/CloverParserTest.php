<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Tests\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sebb\CoverageNotes\Coverage\CloverParser;
use Sebb\CoverageNotes\Coverage\ItemMetrics;
use Sebb\CoverageNotes\Serializer\CoverageSerializer;

final class CloverParserTest extends TestCase
{
    public function testParsesClassesAndProceduralFilesAndRoundTripsJson(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage generated="1700000000">
          <project timestamp="1700000000">
            <file name="/app/src/Service/PaymentService.php">
              <class name="App\Service\PaymentService" namespace="App\Service">
                <metrics complexity="5" methods="3" coveredmethods="2" statements="40" coveredstatements="36"/>
              </class>
              <metrics loc="100" ncloc="80" classes="1" methods="3" coveredmethods="2" statements="40" coveredstatements="36"/>
            </file>
            <file name="/app/src/Contract/GatewayInterface.php">
              <metrics loc="20" ncloc="15" classes="0" methods="0" coveredmethods="0" statements="0" coveredstatements="0"/>
            </file>
            <file name="/app/src/helpers.php">
              <metrics loc="30" ncloc="25" classes="0" methods="0" coveredmethods="0" statements="10" coveredstatements="6"/>
            </file>
            <metrics files="3" loc="150" ncloc="120" classes="1" methods="3" coveredmethods="2" statements="50" coveredstatements="42"/>
          </project>
        </coverage>
        XML;

        $parser = new CloverParser();
        $summary = $parser->parseXml($xml, '/app');

        self::assertSame(1, $summary->version);
        self::assertSame(50, $summary->project->statements);
        self::assertSame(42, $summary->project->covered);
        self::assertSame(84.0, $summary->project->percentage);

        self::assertCount(2, $summary->items);
        self::assertArrayHasKey('App\Service\PaymentService', $summary->items);
        self::assertArrayHasKey('src/helpers.php', $summary->items);

        self::assertSame(90.0, $summary->items['App\Service\PaymentService']->percentage);
        self::assertSame(60.0, $summary->items['src/helpers.php']->percentage);

        $serializer = new CoverageSerializer();
        $json = $serializer->serializeSummary($summary);
        self::assertStringContainsString('"version": 1', $json);
        self::assertStringContainsString('"App\\\\Service\\\\PaymentService": {', $json);
        self::assertStringContainsString('"src/helpers.php": {', $json);

        $rehydrated = $serializer->deserializeSummary($json);
        self::assertEquals($summary, $rehydrated);
    }

    public function testParseFileReadsDiskAndThrowsForMissingFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/clover-parser-test-' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($tempFile, <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <coverage generated="1700000000">
          <project timestamp="1700000000">
            <file name="/outside/path/script.php">
              <metrics statements="20" coveredstatements="15"/>
            </file>
            <file name="/app/src/NoMetrics.php"/>
            <file name="/app/src/DuplicateA.php">
              <class name="\App\SharedClass">
                <metrics statements="5" coveredstatements="2"/>
              </class>
              <metrics statements="5" coveredstatements="2"/>
            </file>
            <file name="/app/src/DuplicateB.php">
              <class name="App\SharedClass">
                <metrics statements="15" coveredstatements="13"/>
              </class>
              <metrics statements="15" coveredstatements="13"/>
            </file>
            <metrics statements="40" coveredstatements="30"/>
          </project>
        </coverage>
        XML);

        try {
            $parser = new CloverParser();
            $summary = $parser->parseFile($tempFile, '/app');

            self::assertSame(75.0, $summary->project->percentage);
            self::assertCount(2, $summary->items);
            self::assertArrayHasKey('/outside/path/script.php', $summary->items);
            self::assertSame(20, $summary->items['App\SharedClass']->statements);
            self::assertSame(15, $summary->items['App\SharedClass']->covered);
            self::assertSame(75.0, $summary->items['App\SharedClass']->percentage);
        } finally {
            @unlink($tempFile);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist or is not readable');
        (new CloverParser())->parseFile('/non-existent/coverage.xml');
    }

    public function testThrowsOnInvalidXml(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Clover XML content.');
        (new CloverParser())->parseXml('<not-valid-xml');
    }

    public function testThrowsWhenProjectMetricsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Clover XML is missing <project><metrics> element.');
        (new CloverParser())->parseXml('<coverage><project/></coverage>');
    }

    public function testCalculatePercentageReturns100WhenNoStatements(): void
    {
        self::assertSame(100.0, ItemMetrics::calculatePercentage(0, 0));
    }
}
