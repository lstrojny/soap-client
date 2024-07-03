<?php

namespace Phpro\SoapClient\CodeGenerator\Assembler;

use Laminas\Code\Generator\DocBlockGenerator;
use Phpro\SoapClient\CodeGenerator\Context\ContextInterface;
use Phpro\SoapClient\CodeGenerator\Context\TypeContext;
use Phpro\SoapClient\CodeGenerator\Model\Property;
use Phpro\SoapClient\CodeGenerator\TypeEnhancer\Calculator\ArrayBoundsCalculator;
use Phpro\SoapClient\Exception\AssemblerException;
use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\MethodGenerator;

/**
 * Class IteratorAssembler
 *
 * @package Phpro\SoapClient\CodeGenerator\Assembler
 */
class IteratorAssembler implements AssemblerInterface
{
    /**
     * @param ContextInterface $context
     *
     * @return bool
     */
    public function canAssemble(ContextInterface $context): bool
    {
        return $context instanceof TypeContext;
    }

    /**
     * @param ContextInterface|TypeContext $context
     *
     * @throws AssemblerException
     */
    public function assemble(ContextInterface $context)
    {
        $class = $context->getClass();
        $properties = $context->getType()->getProperties();
        $firstProperty = count($properties) ? current($properties) : null;

        try {
            $interfaceAssembler = new InterfaceAssembler(\IteratorAggregate::class);
            if ($interfaceAssembler->canAssemble($context)) {
                $interfaceAssembler->assemble($context);
            }

            if ($firstProperty) {
                $this->implementGetIterator($class, $firstProperty);
            }
        } catch (\Exception $e) {
            throw AssemblerException::fromException($e);
        }
    }

    /**
     * @param ClassGenerator $class
     * @param Property       $firstProperty
     *
     * @throws \Laminas\Code\Generator\Exception\InvalidArgumentException
     */
    private function implementGetIterator(ClassGenerator $class, Property $firstProperty)
    {
        $arrayBounds = (new ArrayBoundsCalculator())($firstProperty->getMeta());
        $arrayInfo = '<'.$arrayBounds.', '. $firstProperty->getType() .'>';

        $methodName = 'getIterator';
        $class->removeMethod($methodName);
        $class->addMethodFromGenerator($this->generateGetIteratorMethod($methodName, $firstProperty, $arrayInfo));

        $class->setDocBlock(
            (new DocBlockGenerator())
                ->setWordWrap(false)
                ->setTags([
                    [
                        'name' => 'phpstan-implements',
                        'description' => '\\IteratorAggregate'.$arrayInfo,
                    ],
                    [
                        'name' => 'psalm-implements',
                        'description' => '\\IteratorAggregate'.$arrayInfo,
                    ]
                ])
        );
    }

    private function generateGetIteratorMethod(
        string $methodName,
        Property $firstProperty,
        string $arrayInfo
    ): MethodGenerator {
        $method = new MethodGenerator($methodName);

        $method->setParameters([]);
        $method->setVisibility(MethodGenerator::VISIBILITY_PUBLIC);
        $method->setBody(sprintf(
            'return new \\ArrayIterator($this->%1$s);',
            $firstProperty->getName()
        ));
        $method->setReturnType('ArrayIterator');
        $method->setDocBlock(
            (new DocBlockGenerator())
                ->setWordWrap(false)
                ->setTags([
                    [
                        'name' => 'return',
                        'description' => '\\ArrayIterator|' . $firstProperty->getType() . '[]'
                    ],
                    [
                        'name' => 'phpstan-return',
                        'description' => '\\ArrayIterator' . $arrayInfo,
                    ],
                    [
                        'name' => 'psalm-return',
                        'description' => '\\ArrayIterator' . $arrayInfo,
                    ]
                ])
        );

        return $method;
    }
}
