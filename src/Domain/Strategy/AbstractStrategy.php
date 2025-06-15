<?php

namespace Stochastix\Domain\Strategy;

use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Model\MultiTimeframeOhlcvSeries;
use Stochastix\Domain\Common\Model\Series;
use Stochastix\Domain\Indicator\Model\IndicatorInterface;
use Stochastix\Domain\Indicator\Model\IndicatorManagerInterface;
use Stochastix\Domain\Order\Dto\OrderSignal;
use Stochastix\Domain\Order\Enum\OrderTypeEnum;
use Stochastix\Domain\Order\Model\OrderManagerInterface;
use Stochastix\Domain\Plot\PlotDefinition;
use Stochastix\Domain\Strategy\Attribute\Input;
use Stochastix\Domain\Strategy\Model\StrategyContextInterface;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

abstract class AbstractStrategy implements StrategyInterface
{
    protected private(set) ?IndicatorManagerInterface $indicatorManager = null;
    protected private(set) ?OrderManagerInterface $orderManager = null;
    protected private(set) ?StrategyContextInterface $context = null;

    /** @var array<string, PlotDefinition> */
    private array $plotDefinitions = [];

    final public function configure(array $runtimeParameters): void
    {
        $resolver = new OptionsResolver();
        $this->configureInputOptions($resolver);
        $resolvedInputs = $resolver->resolve($runtimeParameters);

        $reflectionClass = new \ReflectionClass(static::class);

        foreach ($resolvedInputs as $inputName => $value) {
            if ($reflectionClass->hasProperty($inputName)) {
                $property = $reflectionClass->getProperty($inputName);

                if (!empty($property->getAttributes(Input::class))) {
                    $property->setValue($this, $value);
                }
            }
        }

        $this->afterConfigured();
    }

    private function configureInputOptions(OptionsResolver $resolver): void
    {
        $reflectionClass = new \ReflectionClass(static::class);

        foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PUBLIC) as $property) {
            $inputAttributes = $property->getAttributes(Input::class);

            if (empty($inputAttributes)) {
                continue;
            }

            /** @var Input $inputAttribute */
            $inputAttribute = $inputAttributes[0]->newInstance();
            $inputName = $property->getName();
            $propertyType = $property->getType();

            if ($property->hasDefaultValue()) {
                $resolver->setDefault($inputName, $property->getDefaultValue());
            } elseif (!$propertyType?->allowsNull()) {
                $resolver->setRequired($inputName);
            } else {
                $resolver->setDefault($inputName, null);
            }

            // Handle special cases first
            if ($propertyType instanceof \ReflectionNamedType) {
                $typeName = $propertyType->getName();
                // Case 1: Single Backed Enum
                if (is_subclass_of($typeName, \BackedEnum::class)) {
                    $allowedValues = $inputAttribute->choices ?? array_column($typeName::cases(), 'value');
                    $resolver->setAllowedValues($inputName, $allowedValues);
                    $resolver->setNormalizer($inputName, static function ($options, $value) use ($typeName) {
                        if ($value instanceof $typeName) {
                            return $value;
                        }
                        return $typeName::from($value);
                    });
                    continue; // Finished with this property
                }

                // Case 2: Typed Array
                if ($typeName === 'array' && $inputAttribute->arrayType) {
                    $this->configureArrayInput($resolver, $inputName, $inputAttribute);
                    continue; // Finished with this property
                }
            }

            // --- Fallback for all other simple types (int, float, string, etc.) ---

            // 1. Set allowed choices if provided
            if (!empty($inputAttribute->choices)) {
                $resolver->setAllowedValues($inputName, $inputAttribute->choices);
            }

            // 2. Add min/max normalizers for numeric types
            $normalizers = [];
            if ($inputAttribute->min !== null) {
                $normalizers[] = static function ($options, $value) use ($inputAttribute, $inputName) {
                    if ($value !== null && $value < $inputAttribute->min) {
                        throw new InvalidOptionsException(sprintf('Input "%s" with value %s is less than the allowed minimum of %s.', $inputName, is_scalar($value) ? (string) $value : gettype($value), $inputAttribute->min));
                    }
                    return $value;
                };
            }
            if ($inputAttribute->max !== null) {
                $normalizers[] = static function ($options, $value) use ($inputAttribute, $inputName) {
                    if ($value !== null && $value > $inputAttribute->max) {
                        throw new InvalidOptionsException(sprintf('Input "%s" with value %s is greater than the allowed maximum of %s.', $inputName, is_scalar($value) ? (string) $value : gettype($value), $inputAttribute->max));
                    }
                    return $value;
                };
            }
            if (!empty($normalizers)) {
                $resolver->setNormalizer($inputName, function ($options, $value) use ($normalizers) {
                    foreach ($normalizers as $normalizer) {
                        $value = $normalizer($options, $value);
                    }
                    return $value;
                });
            }
        }
    }

    private function configureArrayInput(OptionsResolver $resolver, string $inputName, Input $attribute): void
    {
        $resolver->setNormalizer($inputName, function ($options, $value) use ($attribute, $inputName) {
            if (!is_array($value)) {
                throw new InvalidOptionsException("Option '{$inputName}' must be an array.");
            }

            $count = count($value);
            if ($attribute->minChoices !== null && $count < $attribute->minChoices) {
                throw new InvalidOptionsException("Option '{$inputName}' must have at least {$attribute->minChoices} items.");
            }
            if ($attribute->maxChoices !== null && $count > $attribute->maxChoices) {
                throw new InvalidOptionsException("Option '{$inputName}' cannot have more than {$attribute->maxChoices} items.");
            }

            $itemType = $attribute->arrayType;
            if ($itemType && is_subclass_of($itemType, \BackedEnum::class)) {
                $allowedValues = array_column($itemType::cases(), 'value');
                $convertedValues = [];
                foreach ($value as $item) {
                    if ($item instanceof $itemType) {
                        $convertedValues[] = $item;
                        continue;
                    }
                    if (!in_array($item, $allowedValues, true)) {
                        throw new InvalidOptionsException("Invalid value '{$item}' in '{$inputName}'. Allowed values are: " . implode(', ', $allowedValues));
                    }
                    $convertedValues[] = $itemType::from($item);
                }
                return $convertedValues;
            }

            return $value;
        });
    }

    protected function afterConfigured(): void
    {
    }

    final public function initialize(StrategyContextInterface $context): void
    {
        $this->context = $context;
        $this->indicatorManager = $context->getIndicators();
        $this->orderManager = $context->getOrders();
        $this->defineIndicators();
    }

    abstract protected function defineIndicators(): void;

    final protected function definePlot(
        string $indicatorKey,
        string $name,
        bool $overlay,
        array $plots,
        array $annotations = []
    ): self {
        $this->plotDefinitions[$indicatorKey] = new PlotDefinition(
            name: $name,
            overlay: $overlay,
            plots: $plots,
            annotations: $annotations,
            indicatorKey: $indicatorKey,
        );

        return $this;
    }

    final public function getPlotDefinitions(): array
    {
        return $this->plotDefinitions;
    }

    final public function isInPosition(?string $symbol = null): bool
    {
        if (null === $this->orderManager || null === $this->context) {
            throw new \LogicException('OrderManager or Context not initialized.');
        }

        $checkSymbol = $symbol ?? $this->context->getCurrentSymbol();

        if ($checkSymbol === null) {
            throw new \LogicException('Cannot check position without a symbol or current context symbol.');
        }

        $position = $this->orderManager->getPortfolioManager()->getOpenPosition($checkSymbol);

        return $position !== null;
    }

    final protected function addIndicator(string $key, IndicatorInterface $indicator, ?string $plotName = null): self
    {
        if (null === $this->indicatorManager) {
            throw new \LogicException('Indicators accessed before initialization.');
        }

        $this->indicatorManager->add($key, $indicator);

        if ($plotName !== null) {
            $template = $indicator->getPlotDefinition();

            if ($template !== null) {
                $this->plotDefinitions[$key] = new PlotDefinition(
                    name: $plotName,
                    overlay: $template->overlay,
                    plots: $template->plots,
                    annotations: $template->annotations,
                    indicatorKey: $key
                );
            }
        }

        return $this;
    }

    final protected function getIndicatorSeries(string $indicatorKey, string $seriesKey = 'value'): Series
    {
        if (null === $this->indicatorManager) {
            throw new \LogicException('Indicators accessed before initialization.');
        }

        return $this->indicatorManager->getOutputSeries($indicatorKey, $seriesKey);
    }

    final protected function entry(
        DirectionEnum $direction,
        OrderTypeEnum $orderType,
        float|string $quantity,
        float|string|null $price = null,
        ?int $timeInForceBars = null,
        float|string|null $stopLossPrice = null,
        float|string|null $takeProfitPrice = null,
        ?string $clientOrderId = null,
        array|string|null $enterTags = null
    ): void {
        if (null === $this->orderManager || null === $this->context || null === $this->context->getCurrentSymbol()) {
            throw new \LogicException('OrderManager, Context, or current symbol not set.');
        }

        if (($orderType === OrderTypeEnum::Limit || $orderType === OrderTypeEnum::Stop) && $price === null) {
            throw new \InvalidArgumentException("A price must be provided for {$orderType->value} orders.");
        }

        $tagsAsArray = null;
        if ($enterTags !== null) {
            $tagsAsArray = is_array($enterTags) ? $enterTags : [$enterTags];
        }

        $signal = new OrderSignal(
            symbol: $this->context->getCurrentSymbol(),
            direction: $direction,
            orderType: $orderType,
            quantity: (string) $quantity,
            price: $price !== null ? (string) $price : null,
            timeInForceBars: $timeInForceBars,
            clientOrderId: $clientOrderId ?? uniqid('entry_', true),
            stopLossPrice: $stopLossPrice !== null ? (string) $stopLossPrice : null,
            takeProfitPrice: $takeProfitPrice !== null ? (string) $takeProfitPrice : null,
            enterTags: $tagsAsArray
        );

        $this->orderManager->queueEntry($signal);
    }

    final protected function cancelOrder(string $clientOrderId): void
    {
        if (null === $this->orderManager) {
            throw new \LogicException('OrderManager not initialized.');
        }
        $this->orderManager->cancelPendingOrder($clientOrderId);
    }

    final protected function exit(
        float|string $quantity,
        float|string|null $price = null,
        ?string $clientOrderId = null,
        array|string|null $exitTags = null
    ): void {
        if (null === $this->orderManager || null === $this->context || null === $this->context->getCurrentSymbol()) {
            throw new \LogicException('OrderManager, Context, or current symbol not set.');
        }
        $symbol = $this->context->getCurrentSymbol();

        $openPosition = $this->orderManager->getPortfolioManager()->getOpenPosition($symbol);
        if ($openPosition === null) {
            return;
        }

        $exitDirection = ($openPosition->direction === DirectionEnum::Long) ? DirectionEnum::Short : DirectionEnum::Long;

        $tagsAsArray = null;
        if ($exitTags !== null) {
            $tagsAsArray = is_array($exitTags) ? $exitTags : [$exitTags];
        }

        $signal = new OrderSignal(
            symbol: $symbol,
            direction: $exitDirection,
            orderType: OrderTypeEnum::Market,
            quantity: (string) $quantity,
            price: $price !== null ? (string) $price : null,
            clientOrderId: $clientOrderId ?? uniqid('exit_', true),
            exitTags: $tagsAsArray
        );

        $this->orderManager->queueExit($symbol, $signal);
    }

    abstract public function onBar(MultiTimeframeOhlcvSeries $bars): void;
}
