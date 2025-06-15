<?php

namespace Stochastix\Tests\Domain\Indicator\Model;

use Ds\Map;
use Ds\Vector;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Enum\OhlcvEnum;
use Stochastix\Domain\Common\Enum\TALibFunctionEnum;
use Stochastix\Domain\Indicator\Model\IndicatorManager;
use Stochastix\Domain\Indicator\Model\TALibIndicator;

class IndicatorTest extends TestCase
{
    private BacktestCursor $cursor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cursor = new BacktestCursor();

        if (!function_exists('trader_sma')) {
            $this->markTestSkipped('The trader PECL extension is not available.');
        }
    }

    public function testSmaCalculation(): void
    {
        $closePrices = new Vector([10.0, 11.0, 12.0, 13.0, 14.0]);
        $marketData = [OhlcvEnum::Close->value => $closePrices];
        $dataframes = new Map(['primary' => $marketData]);

        $smaIndicator = new TALibIndicator(TALibFunctionEnum::Sma, ['timePeriod' => 3]);
        $smaIndicator->calculateBatch($dataframes);
        $series = $smaIndicator->getAllSeries()['value'];

        // Expected: [null, null, 11.0, 12.0, 13.0]
        $this->assertNull($series->getVector()[0]);
        $this->assertNull($series->getVector()[1]);
        $this->assertEquals(11.0, $series->getVector()[2]); // (10+11+12)/3
        $this->assertEquals(12.0, $series->getVector()[3]); // (11+12+13)/3
        $this->assertEquals(13.0, $series->getVector()[4]); // (12+13+14)/3
    }

    public function testMacdCalculationCreatesMultipleSeries(): void
    {
        // MACD needs a longer series to warm up
        $closePrices = new Vector(range(100, 135));
        $marketData = [OhlcvEnum::Close->value => $closePrices];
        $dataframes = new Map(['primary' => $marketData]);

        $macdIndicator = new TALibIndicator(TALibFunctionEnum::Macd, [
            'fastPeriod' => 12, 'slowPeriod' => 26, 'signalPeriod' => 9,
        ]);

        $macdIndicator->calculateBatch($dataframes);
        $allSeries = $macdIndicator->getAllSeries();

        $this->assertArrayHasKey('macd', $allSeries);
        $this->assertArrayHasKey('signal', $allSeries);
        $this->assertArrayHasKey('hist', $allSeries);

        // The first 33 values should be null (slowPeriod + signalPeriod - 2)
        $this->assertNull($allSeries['macd']->getVector()[32]);
        $this->assertNotNull($allSeries['macd']->getVector()[33]);
    }

    public function testIndicatorManager(): void
    {
        $closePrices = new Vector([10.0, 11.0, 12.0, 13.0, 14.0]);
        $marketData = [OhlcvEnum::Close->value => $closePrices];
        $dataframes = new Map(['primary' => $marketData]);

        $manager = new IndicatorManager($this->cursor, $dataframes);
        $sma5 = new TALibIndicator(TALibFunctionEnum::Sma, ['timePeriod' => 5]);
        $sma3 = new TALibIndicator(TALibFunctionEnum::Sma, ['timePeriod' => 3]);

        $manager->add('sma_5', $sma5);
        $manager->add('sma_3', $sma3);

        $manager->calculateBatch();

        // Test retrieving a specific series
        $series3 = $manager->getOutputSeries('sma_3', 'value');
        $this->assertEquals(11.0, $series3->getVector()[2]);

        // Test retrieving another series
        $series5 = $manager->getOutputSeries('sma_5', 'value');
        $this->assertNull($series5->getVector()[3]);
        $this->assertEquals(12.0, $series5->getVector()[4]); // (10+11+12+13+14)/5

        // Test that the cursor was set on the series
        $this->cursor->currentIndex = 4;
        $this->assertEquals(13.0, $series3[0]); // Current value
        $this->assertEquals(12.0, $series3[1]); // Previous value
    }

    public function testGetAllOutputDataForSave(): void
    {
        $closePrices = new Vector([10.0, 11.0]);
        $marketData = [OhlcvEnum::Close->value => $closePrices];
        $dataframes = new Map(['primary' => $marketData]);

        $manager = new IndicatorManager($this->cursor, $dataframes);
        $sma2 = new TALibIndicator(TALibFunctionEnum::Sma, ['timePeriod' => 2]);
        $manager->add('sma_2', $sma2);
        $manager->calculateBatch();

        $output = $manager->getAllOutputDataForSave();

        $expected = [
            'sma_2' => [
                'value' => [null, 10.5],
            ],
        ];

        $this->assertEquals($expected, $output);
    }
}
