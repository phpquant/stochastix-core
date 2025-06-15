<?php

namespace Stochastix\Domain\Indicator\Model;

use Ds\Map;
use Ds\Vector;
use Stochastix\Domain\Common\Enum\AppliedPriceEnum;
use Stochastix\Domain\Common\Enum\TALibFunctionEnum;
use Stochastix\Domain\Common\Enum\TimeframeEnum;
use Stochastix\Domain\Common\Model\Series;

class TALibIndicator extends AbstractIndicator
{
    private readonly int $warmUpPeriod;

    public function __construct(
        private readonly TALibFunctionEnum $function,
        private readonly array $parameters,
        private readonly AppliedPriceEnum $source = AppliedPriceEnum::Close,
        public readonly ?TimeframeEnum $sourceTimeframe = null,
    ) {
        $this->warmUpPeriod = $parameters['timePeriod'] ?? ($parameters['slowPeriod'] ?? 1);
    }

    public function calculateBatch(Map $dataframes): void
    {
        $marketData = $dataframes->get($this->sourceTimeframe?->value ?? 'primary');

        if (!isset($marketData[$this->source->value]) || !$marketData[$this->source->value] instanceof Vector) {
            $this->resultSeries[self::DEFAULT_SERIES_KEY] = new Series();

            return;
        }

        /** @var Vector $inputsVector */
        $inputsVector = $marketData[$this->source->value];
        $inputCount = $inputsVector->count();

        if ($inputCount < $this->warmUpPeriod) {
            $this->resultSeries[self::DEFAULT_SERIES_KEY] = new Series();

            return;
        }

        $inputs = $inputsVector->toArray();
        $params = array_values($this->parameters);

        if (!function_exists($this->function->value)) {
            throw new \InvalidArgumentException("Trader function {$this->function->value} does not exist.");
        }

        $rawResult = call_user_func($this->function->value, $inputs, ...$params);

        if ($rawResult === false || empty($rawResult)) {
            $this->resultSeries[self::DEFAULT_SERIES_KEY] = new Series(new Vector(array_fill(0, $inputCount, null)));

            return;
        }

        $firstElement = current($rawResult);
        $results = is_array($firstElement) ? $rawResult : [$rawResult];

        $outputSeriesMap = match ($this->function) {
            TALibFunctionEnum::Macd => ['macd', 'signal', 'hist'],
            default => [self::DEFAULT_SERIES_KEY],
        };

        foreach ($results as $i => $resultArray) {
            $seriesKey = $outputSeriesMap[$i] ?? self::DEFAULT_SERIES_KEY . "_$i";
            $outputCount = count($resultArray);
            $paddingCount = $inputCount - $outputCount;

            if ($paddingCount < 0) {
                throw new \RuntimeException('TA-Lib returned more values than input - unexpected.');
            }

            $calculatedValues = ($paddingCount > 0)
                ? array_merge(array_fill(0, $paddingCount, null), array_values($resultArray))
                : array_values($resultArray);

            $this->resultSeries[$seriesKey] = new Series(new Vector($calculatedValues));
        }
    }
}
