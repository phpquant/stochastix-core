<?php

namespace Stochastix\Tests\Domain\Common\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Model\Series;

class SeriesTest extends TestCase
{
    private BacktestCursor $cursor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cursor = new BacktestCursor();
    }

    public function testArrayAccess(): void
    {
        $values = [10, 20, 30, 40, 50];
        $series = new Series($values, $this->cursor);

        // Test with cursor at the end
        $this->cursor->currentIndex = 4;
        $this->assertTrue(isset($series[0]));
        $this->assertEquals(50, $series[0]); // Current value
        $this->assertEquals(40, $series[1]); // Previous value
        $this->assertEquals(10, $series[4]); // Oldest value
        $this->assertFalse(isset($series[5]));
        $this->assertNull($series[5]);

        // Test with cursor in the middle
        $this->cursor->currentIndex = 2;
        $this->assertEquals(30, $series[0]);
        $this->assertEquals(20, $series[1]);
        $this->assertEquals(10, $series[2]);
        $this->assertFalse(isset($series[3]));
    }

    public static function crossesOverProvider(): array
    {
        // Series A, Series B, Expected Result
        return [
            'successful crossover' => [[9, 11], [10, 10], true],
            'is already over' => [[11, 12], [10, 10], false],
            'is touching from below' => [[9, 10], [10, 10], false],
            'is crossing under' => [[11, 9], [10, 10], false],
            'is equal' => [[10, 10], [10, 10], false],
            'null in current data' => [[9, null], [10, 10], false],
            'null in previous data' => [[null, 11], [10, 10], false],
        ];
    }

    #[DataProvider('crossesOverProvider')]
    public function testCrossesOver(array $a, array $b, bool $expected): void
    {
        $seriesA = new Series($a, $this->cursor);
        $seriesB = new Series($b, $this->cursor);
        $this->cursor->currentIndex = 1; // Set cursor to the latest value

        $this->assertSame($expected, $seriesA->crossesOver($seriesB));
    }

    public static function crossesUnderProvider(): array
    {
        // Series A, Series B, Expected Result
        return [
            'successful crossunder' => [[11, 9], [10, 10], true],
            'is already under' => [[9, 8], [10, 10], false],
            'is touching from above' => [[11, 10], [10, 10], false],
            'is crossing over' => [[9, 11], [10, 10], false],
            'is equal' => [[10, 10], [10, 10], false],
            'null in current data' => [[11, null], [10, 10], false],
            'null in previous data' => [[null, 9], [10, 10], false],
        ];
    }

    #[DataProvider('crossesUnderProvider')]
    public function testCrossesUnder(array $a, array $b, bool $expected): void
    {
        $seriesA = new Series($a, $this->cursor);
        $seriesB = new Series($b, $this->cursor);
        $this->cursor->currentIndex = 1; // Set cursor to the latest value

        $this->assertSame($expected, $seriesA->crossesUnder($seriesB));
    }
}
