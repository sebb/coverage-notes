<?php

declare(strict_types=1);

namespace Sebb\CoverageNotes\Serializer;

use Sebb\CoverageNotes\Coverage\CoverageSummary;
use Sebb\CoverageNotes\Diff\CoverageDiff;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

final readonly class CoverageSerializer
{
    private const int JSON_OPTIONS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    private SerializerInterface $serializer;

    public function __construct(?SerializerInterface $serializer = null)
    {
        $this->serializer = $serializer ?? self::createDefaultSerializer();
    }

    public function serializeSummary(CoverageSummary $summary): string
    {
        return $this->serializer->serialize($summary, JsonEncoder::FORMAT, [
            JsonEncode::OPTIONS => self::JSON_OPTIONS,
        ]) . "\n";
    }

    public function deserializeSummary(string $json): CoverageSummary
    {
        /** @var CoverageSummary $summary */
        $summary = $this->serializer->deserialize($json, CoverageSummary::class, JsonEncoder::FORMAT);

        return $summary;
    }

    public function serializeDiff(CoverageDiff $diff): string
    {
        return $this->serializer->serialize($diff, JsonEncoder::FORMAT, [
            JsonEncode::OPTIONS => self::JSON_OPTIONS,
        ]) . "\n";
    }

    public static function createDefaultSerializer(): Serializer
    {
        $phpDocExtractor = new PhpDocExtractor();
        $reflectionExtractor = new ReflectionExtractor();

        $propertyTypeExtractor = new PropertyInfoExtractor(
            listExtractors: [$reflectionExtractor],
            typeExtractors: [$phpDocExtractor, $reflectionExtractor],
            descriptionExtractors: [$phpDocExtractor],
            accessExtractors: [$reflectionExtractor],
            initializableExtractors: [$reflectionExtractor],
        );

        $objectNormalizer = new ObjectNormalizer(
            classMetadataFactory: new ClassMetadataFactory(new AttributeLoader()),
            nameConverter: new CamelCaseToSnakeCaseNameConverter(),
            propertyTypeExtractor: $propertyTypeExtractor,
        );

        return new Serializer(
            normalizers: [
                new BackedEnumNormalizer(),
                new ArrayDenormalizer(),
                $objectNormalizer,
            ],
            encoders: [
                new JsonEncoder(),
            ],
        );
    }
}
