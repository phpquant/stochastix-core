<?php

namespace Stochastix\Domain\Strategy\Service;

use Stochastix\Domain\Strategy\Attribute\AsStrategy;
use Stochastix\Domain\Strategy\Attribute\Input;
use Stochastix\Domain\Strategy\Dto\InputDefinitionDto;
use Stochastix\Domain\Strategy\Dto\StrategyDefinitionDto;
use Stochastix\Domain\Strategy\StrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class StrategyRegistry implements StrategyRegistryInterface
{
    /** @var array<StrategyDefinitionDto> */
    private array $strategyDefinitions;
    /** @var array<string, AsStrategy> */
    private array $strategyMetadata;

    public function __construct(
        #[AutowireLocator(StrategyInterface::class)] // Using TaggedIterator for Symfony 7+ style
        private iterable $strategies
    ) {
        $definitions = [];
        $metadata = [];

        /** @var StrategyInterface $strategyInstance */
        foreach ($this->strategies as $strategyInstance) {
            $reflectionClass = new \ReflectionClass($strategyInstance);
            $asStrategyAttributes = $reflectionClass->getAttributes(AsStrategy::class);

            if (empty($asStrategyAttributes)) {
                continue;
            }

            /** @var AsStrategy $asStrategy */
            $asStrategy = $asStrategyAttributes[0]->newInstance();
            $metadata[$asStrategy->alias] = $asStrategy;
            $inputs = [];

            foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $property) {
                $inputAttributes = $property->getAttributes(Input::class);
                if (empty($inputAttributes)) {
                    continue;
                }

                /** @var Input $inputAttribute */
                $inputAttribute = $inputAttributes[0]->newInstance();
                $propertyName = $property->getName();
                $propertyType = $property->getType();

                $jsonType = 'string';
                $choices = $inputAttribute->choices;
                $defaultValue = $property->hasDefaultValue() ? $property->getDefaultValue() : null;

                if ($propertyType instanceof \ReflectionNamedType) {
                    $typeName = $propertyType->getName();

                    if (is_subclass_of($typeName, \BackedEnum::class)) {
                        $jsonType = 'string';
                        if ($choices === null) {
                            $choices = array_column($typeName::cases(), 'value');
                        }
                        if ($defaultValue instanceof \BackedEnum) {
                            $defaultValue = $defaultValue->value;
                        }
                    } elseif ($typeName === 'array' && $inputAttribute->arrayType && is_subclass_of($inputAttribute->arrayType, \BackedEnum::class)) {
                        $jsonType = 'array';
                        $choices = array_column($inputAttribute->arrayType::cases(), 'value');
                        if (is_array($defaultValue)) {
                            $defaultValue = array_map(fn ($enum) => $enum->value, $defaultValue);
                        }
                    } else {
                        $jsonType = match ($typeName) {
                            'int' => 'integer',
                            'float' => 'number',
                            'bool' => 'boolean',
                            'string' => 'string',
                            'array' => 'array',
                            default => 'string',
                        };
                    }
                }

                $inputs[] = new InputDefinitionDto(
                    name: $propertyName,
                    description: $inputAttribute->description,
                    type: $jsonType,
                    defaultValue: $defaultValue,
                    min: $inputAttribute->min,
                    max: $inputAttribute->max,
                    choices: $choices,
                    minChoices: $inputAttribute->minChoices,
                    maxChoices: $inputAttribute->maxChoices
                );
            }

            $definitions[] = new StrategyDefinitionDto(
                alias: $asStrategy->alias,
                name: $asStrategy->name,
                description: $asStrategy->description,
                inputs: $inputs,
                timeframe: $asStrategy->timeframe?->value,
                requiredMarketData: array_map(fn ($tf) => $tf->value, $asStrategy->requiredMarketData)
            );
        }
        $this->strategyDefinitions = $definitions;
        $this->strategyMetadata = $metadata;
    }

    public function getStrategyDefinitions(): array
    {
        return $this->strategyDefinitions;
    }

    public function getStrategy(string $strategyAlias): ?StrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            $reflectionClass = new \ReflectionClass($strategy);
            $attributes = $reflectionClass->getAttributes(AsStrategy::class);
            if (!empty($attributes) && $attributes[0]->newInstance()->alias === $strategyAlias) {
                return $strategy;
            }
        }

        return null;
    }

    public function getStrategyMetadata(string $strategyAlias): ?AsStrategy
    {
        return $this->strategyMetadata[$strategyAlias] ?? null;
    }

    public function getStrategies(): iterable
    {
        return $this->strategies;
    }
}
