<?php

namespace Phpro\SoapClient\CodeGenerator\Assembler;

use Laminas\Code\Generator\DocBlockGenerator;
use Laminas\Code\Generator\TypeGenerator;
use Phpro\SoapClient\CodeGenerator\Context\ContextInterface;
use Phpro\SoapClient\CodeGenerator\Context\PropertyContext;
use Phpro\SoapClient\CodeGenerator\LaminasCodeFactory\DocBlockGeneratorFactory;
use Phpro\SoapClient\Exception\AssemblerException;
use Laminas\Code\Generator\PropertyGenerator;
use Soap\Engine\Metadata\Model\TypeMeta;
use Soap\WsdlReader\Metadata\Predicate\IsConsideredNullableType;

/**
 * Class PropertyAssembler
 *
 * @package Phpro\SoapClient\CodeGenerator\Assembler
 */
class PropertyAssembler implements AssemblerInterface
{
    private PropertyAssemblerOptions $options;

    public function __construct(?PropertyAssemblerOptions $options = null)
    {
        $this->options = $options ?? PropertyAssemblerOptions::create();
    }

    /**
     * {@inheritdoc}
     */
    public function canAssemble(ContextInterface $context): bool
    {
        return $context instanceof PropertyContext;
    }

    /**
     * @param ContextInterface|PropertyContext $context
     *
     * @throws AssemblerException
     */
    public function assemble(ContextInterface $context)
    {
        $class = $context->getClass();
        $property = $context->getProperty();

        // Always make sure properties are nullable!
        if ($this->options->useOptionalValue()) {
            $property = $property->withMeta(fn(TypeMeta $meta): TypeMeta => $meta->withIsNullable(true));
        }

        try {
            // This makes it easier to overwrite the default property assembler with your own:
            // It will remove the existing one and recreate the property
            if ($class->hasProperty($property->getName())) {
                $class->removeProperty($property->getName());
            }

            $propertyGenerator = new PropertyGenerator($property->getName());

            $propertyGenerator->setVisibility($this->options->visibility());
            $propertyGenerator->omitDefaultValue(
                !$this->options->useOptionalValue() && !(new IsConsideredNullableType())($property->getMeta())
            );

            if ($this->options->useDocBlocks()) {
                $propertyGenerator->setDocBlock(
                    (new DocBlockGenerator())
                        ->setWordWrap(false)
                        ->setLongDescription($property->getMeta()->docs()->unwrapOr(''))
                        ->setTags([
                            [
                                'name'        => 'var',
                                'description' => $property->getDocBlockType(),
                            ],
                        ])
                );
            }

            if ($this->options->useTypeHints() && method_exists($propertyGenerator, 'setType')) {
                $propertyGenerator->setType(TypeGenerator::fromTypeString($property->getPhpType()));
            }

            $class->addPropertyFromGenerator($propertyGenerator);
        } catch (\Exception $e) {
            throw AssemblerException::fromException($e);
        }
    }
}
