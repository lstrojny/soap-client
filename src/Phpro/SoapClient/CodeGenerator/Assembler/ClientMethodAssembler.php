<?php

namespace Phpro\SoapClient\CodeGenerator\Assembler;

use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\DocBlockGenerator;
use Laminas\Code\Generator\MethodGenerator;
use Laminas\Code\Generator\ParameterGenerator;
use Phpro\SoapClient\CodeGenerator\Context\ClientMethodContext;
use Phpro\SoapClient\CodeGenerator\Context\ContextInterface;
use Phpro\SoapClient\CodeGenerator\GeneratorInterface;
use Phpro\SoapClient\CodeGenerator\Model\ClientMethod;
use Phpro\SoapClient\CodeGenerator\Util\Normalizer;
use Phpro\SoapClient\Exception\AssemblerException;
use Phpro\SoapClient\Exception\SoapException;
use Phpro\SoapClient\Type\MixedResult;
use Phpro\SoapClient\Type\MultiArgumentRequest;
use Phpro\SoapClient\Type\RequestInterface;
use Phpro\SoapClient\Type\ResultInterface;
use function Psl\Type\non_empty_string;

class ClientMethodAssembler implements AssemblerInterface
{
    /**
     * {@inheritdoc}
     */
    public function canAssemble(ContextInterface $context): bool
    {
        return $context instanceof ClientMethodContext;
    }

    /**
     * @param ContextInterface|ClientMethodContext $context
     *
     * @return bool
     * @throws AssemblerException
     */
    public function assemble(ContextInterface $context): bool
    {
        if (!$context instanceof ClientMethodContext) {
            throw new AssemblerException(
                __METHOD__.' expects an '.ClientMethodContext::class.' as input '.get_class($context).' given'
            );
        }
        $class = $context->getClass();
        $method = $context->getMethod();
        try {
            $phpMethodName = Normalizer::normalizeMethodName($method->getMethodName());
            $param = $this->createParamsFromContext($context);
            $class->removeMethod($phpMethodName);
            $docblock = $method->shouldGenerateAsMultiArgumentsRequest()
                ? $this->generateMultiArgumentDocblock($context)
                : $this->generateSingleArgumentDocblock($context);
            $methodBody = $this->generateMethodBody($class, $param, $method, $context);

            $class->addMethodFromGenerator(
                $this->generateMethod($phpMethodName, $param, $methodBody, $context, $docblock)
            );
        } catch (\Exception $e) {
            throw AssemblerException::fromException($e);
        }

        return true;
    }

    private function generateMethod(
        string $phpMethodName,
        ?ParameterGenerator $param,
        string $methodBody,
        ClientMethodContext $context,
        DocBlockGenerator $docblock
    ): MethodGenerator {
        $method = new MethodGenerator($phpMethodName);

        $method->setParameters($param === null ? [] : [$param]);
        $method->setVisibility(MethodGenerator::VISIBILITY_PUBLIC);
        $method->setBody($methodBody);
        $method->setReturnType($this->decideOnReturnType($context, true));
        $method->setDocBlock($docblock);

        return $method;
    }

    private function generateMethodBody(
        ClassGenerator $class,
        ?ParameterGenerator $param,
        ClientMethod $method,
        $context
    ): string {
        $assertInstanceOf = static fn (string $class): string =>
            '\\Psl\\Type\\instance_of(\\'.ltrim($class, '\\').'::class)->assert($response);';

        $code = [
            sprintf(
                '$response = ($this->caller)(\'%s\', %s);',
                $method->getMethodName(),
                $param === null
                    ? 'new '.$this->generateClassNameAndAddImport(MultiArgumentRequest::class, $class).'([])'
                    : '$'.$param->getName()
            ),
            '',
            $assertInstanceOf($this->decideOnReturnType($context, true)),
            $assertInstanceOf(ResultInterface::class),
            '',
            'return $response;',
        ];

        return implode($class::LINE_FEED, $code);
    }

    /**
     * @param ClientMethodContext $context
     *
     * @return ParameterGenerator|null
     */
    private function createParamsFromContext(ClientMethodContext $context): ?ParameterGenerator
    {
        $method = $context->getMethod();
        $paramsCount = $method->getParametersCount();

        if ($paramsCount === 0) {
            return null;
        }

        if (!$method->shouldGenerateAsMultiArgumentsRequest()) {
            $param = current($context->getMethod()->getParameters());

            return new ParameterGenerator($param->getName(), $param->getType());
        }

        return new ParameterGenerator('multiArgumentRequest', MultiArgumentRequest::class);
    }

    /**
     * @param ClientMethodContext $context
     *
     * @return DocBlockGenerator
     */
    private function generateMultiArgumentDocblock(ClientMethodContext $context): DocBlockGenerator
    {
        $class = $context->getClass();
        $description = ['MultiArgumentRequest with following params:'. GeneratorInterface::EOL];
        foreach ($context->getMethod()->getParameters() as $parameter) {
            $description[] = $parameter->getType().' $'.$parameter->getName();
        }

        return (new DocBlockGenerator())
            ->setWordWrap(false)
            ->setShortDescription($context->getMethod()->getMeta()->docs()->unwrapOr(''))
            ->setLongDescription(implode(GeneratorInterface::EOL, $description))
            ->setTags([
                [
                    'name' => 'param',
                    'description' => sprintf(
                        '%s $%s',
                        $this->generateClassNameAndAddImport(
                            MultiArgumentRequest::class,
                            $class
                        ),
                        'multiArgumentRequest'
                    ),
                ],
                [
                    'name' => 'return',
                    'description' => sprintf(
                        '%s & %s',
                        $this->generateClassNameAndAddImport(ResultInterface::class, $class),
                        $this->decideOnReturnType($context, false)
                    ),
                ],
                [
                    'name' => 'throws',
                    'description' => $this->generateClassNameAndAddImport(
                        SoapException::class,
                        $class
                    ),
                ],
            ]);
    }

    /**
     * @param ClientMethodContext $context
     *
     * @return DocBlockGenerator
     */
    private function generateSingleArgumentDocblock(ClientMethodContext $context): DocBlockGenerator
    {
        $method = $context->getMethod();
        $class = $context->getClass();
        $param = current($method->getParameters());

        $shortDescription = $context->getMethod()->getMeta()->docs()->unwrapOr('');
        $tags = [
            [
                'name' => 'return',
                'description' => sprintf(
                    '%s & %s',
                    $this->generateClassNameAndAddImport(ResultInterface::class, $class),
                    $this->decideOnReturnType($context, false)
                ),
            ],
            [
                'name' => 'throws',
                'description' => $this->generateClassNameAndAddImport(
                    SoapException::class,
                    $class
                ),
            ],
        ];

        if ($param) {
            array_unshift(
                $tags,
                [
                    'name' => 'param',
                    'description' => sprintf(
                        '%s & %s $%s',
                        $this->generateClassNameAndAddImport(RequestInterface::class, $class),
                        $this->generateClassNameAndAddImport($param->getType(), $class, true),
                        $param->getName()
                    ),
                ]
            );
        }

        return (new DocBlockGenerator())
            ->setWordWrap(false)
            ->setShortDescription($shortDescription)
            ->setTags($tags);
    }

    /**
     * @param non-empty-string $fqcn Fully qualified class name.
     * @param ClassGenerator $class Class generator object.
     * @param bool $prefixed
     *
     * @return non-empty-string
     */
    protected function generateClassNameAndAddImport(string $fqcn, ClassGenerator $class, $prefixed = false): string
    {
        if (Normalizer::isKnownType($fqcn)) {
            return $fqcn;
        }
        $prefix = '';
        $fqcn = ltrim($fqcn, '\\');
        $parts = explode('\\', $fqcn);
        $className = array_pop($parts);
        if ($prefixed) {
            $prefix = array_pop($parts);
        }
        $classNamespace = implode('\\', $parts);
        $currentNamespace = (string)$class->getNamespaceName();
        if ($prefixed) {
            $className = $prefix.'\\'.$className;
            $fqcn = $classNamespace.'\\'.$prefix;
        }
        if ($classNamespace !== $currentNamespace || !\in_array($fqcn, $class->getUses(), true)) {
            $class->addUse(non_empty_string()->assert($fqcn));
        }

        return non_empty_string()->assert($className);
    }

    /**
     * @return non-empty-string
     */
    protected function decideOnReturnType(ClientMethodContext $context, bool $useFqcn): string
    {
        $class = $context->getClass();
        $returnType = $context->getMethod()->getReturnType();

        if ($returnType->shouldGenerateAsMixedResult()) {
            if ($useFqcn) {
                return MixedResult::class;
            }

            return $this->generateClassNameAndAddImport(MixedResult::class, $class) . '<'.$returnType->getType().'>';
        }

        if ($useFqcn) {
            return $returnType->getType();
        }

        return $this->generateClassNameAndAddImport($returnType->getType(), $class, true);
    }
}
