<?php

namespace Phpro\SoapClient\CodeGenerator\Assembler;

use Laminas\Code\Generator\DocBlockGenerator;
use Phpro\SoapClient\CodeGenerator\Context\ContextInterface;
use Phpro\SoapClient\CodeGenerator\Context\TypeContext;
use Phpro\SoapClient\CodeGenerator\Model\Property;
use Phpro\SoapClient\CodeGenerator\Util\Normalizer;
use Phpro\SoapClient\Exception\AssemblerException;
use Phpro\SoapClient\Type\ResultInterface;
use Phpro\SoapClient\Type\ResultProviderInterface;
use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\MethodGenerator;

/**
 * Class ResultProviderAssembler
 *
 * @package Phpro\SoapClient\CodeGenerator\Assembler
 */
class ResultProviderAssembler implements AssemblerInterface
{
    /**
     * @var null|non-empty-string
     */
    private $wrapperClass;

    /**
     * ResultProviderAssembler constructor.
     *
     * @param non-empty-string $wrapperClass
     */
    public function __construct(string $wrapperClass = null)
    {
        $wrapperClass = ($wrapperClass !== null) ? ltrim($wrapperClass, '\\') : null;

        $this->wrapperClass = $wrapperClass ?: null;
    }

    /**
     * {@inheritdoc}
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
            $interfaceAssembler = new InterfaceAssembler(ResultProviderInterface::class);
            if ($interfaceAssembler->canAssemble($context)) {
                $interfaceAssembler->assemble($context);
            }

            if ($firstProperty) {
                $this->implementGetResult($context, $class, $firstProperty);
            }
        } catch (\Exception $e) {
            throw AssemblerException::fromException($e);
        }
    }

    /**
     * @param ContextInterface $context
     * @param ClassGenerator   $class
     * @param Property         $property
     *
     * @throws \Laminas\Code\Generator\Exception\InvalidArgumentException
     */
    private function implementGetResult(ContextInterface $context, ClassGenerator $class, Property $property)
    {
        $useAssembler = new UseAssembler($this->wrapperClass ?: ResultInterface::class);
        if ($useAssembler->canAssemble($context)) {
            $useAssembler->assemble($context);
        }

        $methodName = 'getResult';
        $class->removeMethod($methodName);
        $class->addMethodFromGenerator($this->generateGetResultMethod($methodName, $property));
    }

    private function generateGetResultMethod(string $methodName, Property $property): MethodGenerator
    {
        $method = new MethodGenerator($methodName);

        $method->setParameters([]);
        $method->setVisibility(MethodGenerator::VISIBILITY_PUBLIC);
        $method->setReturnType(ResultInterface::class);
        $method->setBody($this->generateGetResultBody($property));
        $method->setDocBlock(
            (new DocBlockGenerator())
                ->setWordWrap(false)
                ->setTags([
                    [
                        'name' => 'return',
                        'description' => $this->generateGetResultReturnTag($property)
                    ]
                ])
        );

        return $method;
    }

    /**
     * @param Property $property
     *
     * @return non-empty-string
     */
    private function generateGetResultBody(Property $property): string
    {
        if ($this->wrapperClass === null) {
            return sprintf('return $this->%s;', $property->getName());
        }

        return sprintf(
            'return new %s($this->%s);',
            Normalizer::getClassNameFromFQN($this->wrapperClass),
            $property->getName()
        );
    }

    /**
     * @param Property $property
     *
     * @return non-empty-string
     */
    private function generateGetResultReturnTag(Property $property): string
    {
        if ($this->wrapperClass === null) {
            return $property->getType() . '|' . Normalizer::getClassNameFromFQN(ResultInterface::class);
        }

        return Normalizer::getClassNameFromFQN($this->wrapperClass);
    }
}
