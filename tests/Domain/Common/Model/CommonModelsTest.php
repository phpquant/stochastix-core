<?php

namespace Stochastix\Tests\Domain\Common\Model;

use Ds\Vector;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Enum\OhlcvEnum;
use Stochastix\Domain\Common\Model\MutableSeries;
use Stochastix\Domain\Common\Model\OhlcvSeries;
use Stochastix\Domain\Common\Model\Series;

class CommonModelsTest extends TestCase
{
    public function testOhlcvSeriesInitialization(): void
    {
        $cursor = new BacktestCursor();
        $marketData = [
            OhlcvEnum::Timestamp->value => new Vector([1735693200]),
            OhlcvEnum::Open->value => new Vector([100.0]),
            OhlcvEnum::High->value => new Vector([102.0]),
            OhlcvEnum::Low->value => new Vector([99.0]),
            OhlcvEnum::Close->value => new Vector([101.0]),
            OhlcvEnum::Volume->value => new Vector([1000.0]),
        ];

        $ohlcvSeries = new OhlcvSeries($marketData, $cursor);

        $this->assertInstanceOf(Series::class, $ohlcvSeries->open);
        $this->assertInstanceOf(Series::class, $ohlcvSeries->high);
        $this->assertInstanceOf(Series::class, $ohlcvSeries->low);
        $this->assertInstanceOf(Series::class, $ohlcvSeries->close);
        $this->assertInstanceOf(Series::class, $ohlcvSeries->volume);
        $this->assertInstanceOf(Series::class, $ohlcvSeries->timestamp);

        // Check if data is accessible
        $cursor->currentIndex = 0;
        $this->assertEquals(101.0, $ohlcvSeries->close[0]);
    }

    public function testMutableSeriesAppend(): void
    {
        $series = new MutableSeries([10, 20]);
        $this->assertEquals(2, $series->count());

        $series->append(30);
        $this->assertEquals(3, $series->count());

        // After appending, the current index should be the last element
        $series->setCurrentIndex(2);
        $this->assertEquals(30, $series[0]);
        $this->assertEquals(20, $series[1]);
    }

    public function testMutableSeriesSetCurrentIndex(): void
    {
        $series = new MutableSeries([10, 20, 30, 40]);

        // Default index is last element
        $series->setCurrentIndex(3);
        $this->assertEquals(40, $series[0]);
        $this->assertEquals(30, $series[1]);

        // Set index to the middle
        $series->setCurrentIndex(1);
        $this->assertEquals(20, $series[0]);
        $this->assertEquals(10, $series[1]);
    }

    public function testMutableSeriesThrowsOnInvalidIndex(): void
    {
        $series = new MutableSeries([10, 20]);

        $this->expectException(\OutOfBoundsException::class);
        $series->setCurrentIndex(5);
    }
}
