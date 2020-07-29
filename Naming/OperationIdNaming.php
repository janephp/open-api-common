<?php

namespace Jane\OpenApiCommon\Naming;

use Doctrine\Common\Inflector\Inflector;
use Jane\OpenApiCommon\Guesser\Guess\OperationGuess;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

class OperationIdNaming implements OperationNamingInterface
{
    /** @var SluggerInterface */
    private $slugger;

    public function __construct()
    {
        $this->slugger = new AsciiSlugger();
    }

    public function getFunctionName(OperationGuess $operation): string
    {
        return Inflector::camelize($this->slugger->slug((string) $operation->getOperation()->getOperationId()));
    }

    public function getEndpointName(OperationGuess $operation): string
    {
        $operationId = (string) $operation->getOperation()->getOperationId();
        $operationId = $this->slugger->slug($operationId, '-');

        return Inflector::classify($operationId);
    }
}
