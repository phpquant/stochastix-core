<?php

namespace Stochastix\Domain\Chart\Service;

use Stochastix\Domain\Indicator\Attribute\AsIndicator;
use Stochastix\Domain\Indicator\Model\IndicatorInterface;
use Stochastix\Domain\Indicator\Model\TALibIndicator;
use Stochastix\Domain\Strategy\Attribute\Input;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * This service discovers all available indicators, both built-in (TA-Lib)
 * and custom-defined, and prepares them for the API.
 */
final readonly class IndicatorDiscoveryService
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $builtInIndicatorDefinitions;

    /**
     * @param iterable<IndicatorInterface> $indicators
     */
    public function __construct(
        #[AutowireIterator(IndicatorInterface::class)]
        private iterable $indicators
    ) {
        $this->builtInIndicatorDefinitions = $this->getBuiltInDefinitions();
    }

    public function getAvailableIndicators(): array
    {
        return [
            'builtIn' => array_values($this->builtInIndicatorDefinitions),
            'custom' => $this->discoverCustomIndicators(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getBuiltInDefinitions(): array
    {
        // This mapping provides user-friendly metadata for common TA-Lib functions.
        $map = [
            'sma' => ['name' => 'Simple Moving Average', 'params' => ['timePeriod']],
            'ema' => ['name' => 'Exponential Moving Average', 'params' => ['timePeriod']],
            'rsi' => ['name' => 'Relative Strength Index', 'params' => ['timePeriod']],
            'macd' => ['name' => 'Moving Average Convergence Divergence', 'params' => ['fastPeriod', 'slowPeriod', 'signalPeriod']],
            'bbands' => ['name' => 'Bollinger Bands', 'params' => ['timePeriod', 'nbDevUp', 'nbDevDn', 'mAType']],
            'atr' => ['name' => 'Average True Range', 'params' => ['timePeriod']],
            'stoch' => ['name' => 'Stochastic', 'params' => ['fastK_Period', 'slowK_Period', 'slowK_MAType', 'slowD_Period', 'slowD_MAType']],
            'adx' => ['name' => 'Average Directional Movement Index', 'params' => ['timePeriod']],
            'obv' => ['name' => 'On Balance Volume', 'params' => []],
        ];

        $definitions = [];
        foreach ($map as $func => $details) {
            $definitions[$func] = [
                'name' => $details['name'],
                'description' => null,
                'alias' => $func,
                'type' => 'talib',
                'inputs' => array_map(fn($p) => ['name' => $p, 'type' => str_contains(strtolower($p), 'period') ? 'integer' : 'number'], $details['params']),
            ];
        }

        return $definitions;
    }

    private function discoverCustomIndicators(): array
    {
        $customIndicators = [];
        foreach ($this->indicators as $indicator) {
            // We only care about custom implementations, not the generic TALibIndicator itself.
            if ($indicator instanceof TALibIndicator) {
                continue;
            }

            $reflectionClass = new \ReflectionClass($indicator);
            $asIndicatorAttributes = $reflectionClass->getAttributes(AsIndicator::class);

            if (empty($asIndicatorAttributes)) {
                continue;
            }

            /** @var AsIndicator $asIndicator */
            $asIndicator = $asIndicatorAttributes[0]->newInstance();
            $inputs = [];

            foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $property) {
                $inputAttributes = $property->getAttributes(Input::class);
                if (empty($inputAttributes)) {
                    continue;
                }

                $inputAttr = $inputAttributes[0]->newInstance();
                $propertyType = $property->getType()?->getName() ?? 'string';
                $jsonType = match ($propertyType) {
                    'int' => 'integer',
                    'float' => 'number',
                    'bool' => 'boolean',
                    default => 'string',
                };

                $inputs[] = [
                    'name' => $property->getName(),
                    'description' => $inputAttr->description,
                    'type' => $jsonType,
                    'defaultValue' => $property->hasDefaultValue() ? $property->getDefaultValue() : null,
                ];
            }

            $customIndicators[] = [
                'name' => $asIndicator->name,
                'description' => $asIndicator->description,
                'alias' => $reflectionClass->getName(), // Use FQCN as the unique identifier
                'type' => 'custom',
                'inputs' => $inputs,
            ];
        }
        return $customIndicators;
    }
}
