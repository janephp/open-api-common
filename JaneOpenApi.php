<?php

namespace Jane\OpenApiCommon;

use Jane\JsonSchema\Generator\ChainGenerator;
use Jane\JsonSchema\Generator\Context\Context;
use Jane\JsonSchema\Generator\Naming;
use Jane\JsonSchema\Guesser\ChainGuesser;
use Jane\JsonSchema\Registry\Registry;
use Jane\OpenApiCommon\Contracts\WhitelistFetchInterface;
use Jane\OpenApiCommon\Guesser\Guess\ClassGuess;
use Jane\OpenApiCommon\Guesser\Guess\MultipleClass;
use Jane\OpenApiCommon\Registry\Registry as OpenApiRegistry;
use Jane\OpenApiCommon\Registry\Schema;
use Jane\OpenApiCommon\SchemaParser\SchemaParser;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\YamlEncoder;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

abstract class JaneOpenApi extends ChainGenerator
{
    protected const OBJECT_NORMALIZER_CLASS = null;
    protected const WHITELIST_FETCH_CLASS = null;

    /** @var SchemaParser */
    protected $schemaParser;

    /** @var ChainGuesser */
    protected $chainGuesser;

    /** @var Naming */
    protected $naming;

    /** @var bool */
    protected $strict;

    /** @var SerializerInterface */
    protected $serializer;

    public function __construct(
        SchemaParser $schemaParser,
        ChainGuesser $chainGuesser,
        Naming $naming,
        bool $strict = true
    ) {
        $this->schemaParser = $schemaParser;
        $this->chainGuesser = $chainGuesser;
        $this->strict = $strict;
        $this->naming = $naming;
        $this->serializer = self::buildSerializer();
    }

    /**
     * @param OpenApiRegistry $registry
     */
    public function createContext(Registry $registry): Context
    {
        /** @var Schema[] $schemas */
        $schemas = array_values($registry->getSchemas());

        foreach ($schemas as $schema) {
            $openApiSpec = $this->schemaParser->parseSchema($schema->getOrigin());
            $this->chainGuesser->guessClass($openApiSpec, $schema->getRootName(), $schema->getOrigin() . '#', $registry);
            $schema->setParsed($openApiSpec);
        }

        foreach ($schemas as $schema) {
            foreach ($schema->getClasses() as $class) {
                $properties = $this->chainGuesser->guessProperties($class->getObject(), $schema->getRootName(), $class->getReference(), $registry);
                $names = [];

                foreach ($properties as $property) {
                    $property->setPhpName($this->naming->getPropertyName($property->getName()));

                    $i = 2;
                    $newName = $property->getPhpName();

                    while (\in_array(strtolower($newName), $names, true)) {
                        $newName = $property->getPhpName() . $i;
                        ++$i;
                    }

                    if ($newName !== $property->getPhpName()) {
                        $property->setPhpName($newName);
                    }

                    $names[] = strtolower($property->getPhpName());
                    $property->setType($this->chainGuesser->guessType($property->getObject(), $property->getName(), $property->getReference(), $registry));
                }

                $class->setProperties($properties);
                $schema->addClassRelations($class);

                $extensionsTypes = [];

                foreach ($class->getExtensionsObject() as $pattern => $extensionData) {
                    $extensionsTypes[$pattern] = $this->chainGuesser->guessType($extensionData['object'], $class->getName(), $extensionData['reference'], $registry);
                }

                $class->setExtensionsType($extensionsTypes);
            }

            $this->hydrateDiscriminatedClasses($schema, $registry);

            // when we have a whitelist, we want to have only needed models to be generated
            if (\count($registry->getWhitelistedPaths() ?? []) > 0) {
                $this->whitelistFetch($schema, $registry);
            }
        }

        return new Context($registry, $this->strict);
    }

    /**
     * @param OpenApiRegistry $registry
     */
    protected function whitelistFetch(Schema $schema, Registry $registry): void
    {
        $whitelistFetchClass = static::WHITELIST_FETCH_CLASS;
        /** @var WhitelistFetchInterface $whitelistedSchema */
        $whitelistedSchema = new $whitelistFetchClass($schema, self::buildSerializer());

        foreach ($schema->getOperations() as $operation) {
            $whitelistedSchema->addOperationRelations($operation, $registry);
        }

        foreach ($schema->getClasses() as $class) {
            if (!$schema->needsRelation($class->getName())) {
                $schema->removeClass($class->getReference());
            }
        }
    }

    protected function hydrateDiscriminatedClasses(Schema $schema, Registry $registry)
    {
        foreach ($schema->getClasses() as $class) {
            if ($class instanceof MultipleClass) { // is parent class
                foreach ($class->getReferences() as $reference) {
                    $guess = $registry->getClass($reference);
                    if ($guess instanceof ClassGuess) { // is child class
                        $guess->setMultipleClass($class);
                    }
                }
            }
        }
    }

    public static function buildSerializer()
    {
        $encoders = [
            new JsonEncoder(new JsonEncode([JsonEncode::OPTIONS => JSON_UNESCAPED_SLASHES]), new JsonDecode()),
            new YamlEncoder(new Dumper(), new Parser()),
        ];

        $objectNormalizerClass = static::OBJECT_NORMALIZER_CLASS;

        return new Serializer([new $objectNormalizerClass()], $encoders);
    }
}
