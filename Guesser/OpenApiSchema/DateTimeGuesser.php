<?php

namespace Jane\OpenApiCommon\Guesser\OpenApiSchema;

use Jane\JsonSchema\Guesser\JsonSchema\DateTimeGuesser as BaseDateTimeGuesser;

class DateTimeGuesser extends BaseDateTimeGuesser
{
    use SchemaClassTrait;

    public function __construct(string $schemaClass, string $outputDateFormat = \DateTime::RFC3339, ?string $inputDateFormat = null, ?bool $preferInterface = null)
    {
        parent::__construct($outputDateFormat, $inputDateFormat, $preferInterface);
        $this->schemaClass = $schemaClass;
    }
}
