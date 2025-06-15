<?php

namespace Stochastix\Tests\Domain\Strategy;

use Ds\Map;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Model\MultiTimeframeOhlcvSeries;
use Stochastix\Domain\Common\Model\Series;
use Stochastix\Domain\Indicator\Model\IndicatorInterface;
use Stochastix\Domain\Indicator\Model\IndicatorManagerInterface;
use Stochastix\Domain\Order\Dto\OrderSignal;
use Stochastix\Domain\Order\Dto\PositionDto;
use Stochastix\Domain\Order\Enum\OrderTypeEnum;
use Stochastix\Domain\Order\Model\OrderManagerInterface;
use Stochastix\Domain\Order\Model\PortfolioManagerInterface;
use Stochastix\Domain\Plot\PlotDefinition;
use Stochastix\Domain\Plot\Series\Line;
use Stochastix\Domain\Strategy\AbstractStrategy;
use Stochastix\Domain\Strategy\Attribute\Input;
use Stochastix\Domain\Strategy\Model\StrategyContext;
use Stochastix\Domain\Strategy\Model\StrategyContextInterface;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

class AbstractStrategyTest extends TestCase
{
    private OrderManagerInterface $orderManagerMock;
    private IndicatorManagerInterface $indicatorManagerMock;
    private PortfolioManagerInterface $portfolioManagerMock;
    private StrategyContextInterface $strategyContextMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->portfolioManagerMock = $this->createMock(PortfolioManagerInterface::class);
        $this->orderManagerMock = $this->createMock(OrderManagerInterface::class);
        $this->indicatorManagerMock = $this->createMock(IndicatorManagerInterface::class);
        $this->strategyContextMock = $this->createMock(StrategyContextInterface::class);

        $this->strategyContextMock->method('getOrders')->willReturn($this->orderManagerMock);
        $this->strategyContextMock->method('getIndicators')->willReturn($this->indicatorManagerMock);
        $this->orderManagerMock->method('getPortfolioManager')->willReturn($this->portfolioManagerMock);
    }

    private function createInitializedStrategy(): ConcreteTestStrategy
    {
        $strategy = new ConcreteTestStrategy();
        $strategy->initialize($this->strategyContextMock);

        return $strategy;
    }

    public function testConfigureResolvesAndSetsInputs(): void
    {
        $strategy = new ConcreteTestStrategy();
        $strategy->configure(['emaPeriod' => 50, 'someFlag' => false]);

        $reflection = new \ReflectionClass(ConcreteTestStrategy::class);
        $emaProp = $reflection->getProperty('emaPeriod');
        $flagProp = $reflection->getProperty('someFlag');

        $this->assertEquals(50, $emaProp->getValue($strategy));
        $this->assertEquals(false, $flagProp->getValue($strategy));
    }

    public function testConfigureThrowsOnInvalidInput(): void
    {
        $strategy = new ConcreteTestStrategy();
        $this->expectException(InvalidOptionsException::class);
        $strategy->configure(['emaPeriod' => 0]);
    }

    public function testEntryMethodCallsOrderManagerQueueEntry(): void
    {
        $this->strategyContextMock->method('getCurrentSymbol')->willReturn('BTC/USDT');
        $strategy = $this->createInitializedStrategy();

        $this->orderManagerMock->expects($this->once())
            ->method('queueEntry')
            ->with($this->callback(
                fn (OrderSignal $signal) => $signal->symbol === 'BTC/USDT'
                    && $signal->direction === DirectionEnum::Long
                    && $signal->quantity === '1.5'
            ));

        $strategy->testEntry();
    }

    public function testExitMethodCallsOrderManagerQueueExit(): void
    {
        $this->strategyContextMock->method('getCurrentSymbol')->willReturn('BTC/USDT');
        $strategy = $this->createInitializedStrategy();

        $dummyPosition = new PositionDto('pos-1', 'BTC/USDT', DirectionEnum::Long, '50000', '1.0', new \DateTimeImmutable());
        $this->portfolioManagerMock->method('getOpenPosition')->with('BTC/USDT')->willReturn($dummyPosition);

        $this->orderManagerMock->expects($this->once())
            ->method('queueExit')
            ->with('BTC/USDT', $this->callback(
                fn (OrderSignal $signal) => $signal->direction === DirectionEnum::Short
                    && $signal->quantity === '1.0'
            ));

        $strategy->testExit();
    }

    public function testIsInPositionReturnsFalseWhenNoPositionExists(): void
    {
        $this->strategyContextMock->method('getCurrentSymbol')->willReturn('BTC/USDT');
        $this->portfolioManagerMock->method('getOpenPosition')->with('BTC/USDT')->willReturn(null);

        $strategy = $this->createInitializedStrategy();

        $this->assertFalse($strategy->isInPosition());
    }

    public function testIsInPositionReturnsTrueWhenPositionExists(): void
    {
        $this->strategyContextMock->method('getCurrentSymbol')->willReturn('BTC/USDT');
        $dummyPosition = new PositionDto('p1', 'BTC/USDT', DirectionEnum::Long, '1', '1', new \DateTimeImmutable());
        $this->portfolioManagerMock->method('getOpenPosition')->with('BTC/USDT')->willReturn($dummyPosition);

        $strategy = $this->createInitializedStrategy();

        $this->assertTrue($strategy->isInPosition());
    }

    public function testAddAndGetIndicatorSeries(): void
    {
        $indicatorManagerMock = $this->createMock(IndicatorManagerInterface::class);
        $strategyContext = new StrategyContext(
            $indicatorManagerMock,
            $this->createMock(OrderManagerInterface::class),
            new BacktestCursor(),
            new Map()
        );
        $strategy = new ConcreteTestStrategy();
        $strategy->initialize($strategyContext);

        $indicatorMock = $this->createMock(IndicatorInterface::class);
        $seriesMock = $this->createMock(Series::class);

        $indicatorManagerMock->expects($this->once())->method('add')->with('my_indicator', $indicatorMock);
        $indicatorManagerMock->method('getOutputSeries')->with('my_indicator', 'value')->willReturn($seriesMock);

        $strategy->testAddIndicator('my_indicator', $indicatorMock);
        $retrievedSeries = $strategy->testGetIndicatorSeries('my_indicator', 'value');

        $this->assertSame($seriesMock, $retrievedSeries);
    }

    public function testDefineAndGetPlotDefinitions(): void
    {
        $strategy = new ConcreteTestStrategy();
        $strategy->initialize($this->createMock(StrategyContextInterface::class));

        $strategy->testDefinePlot();
        $definitions = $strategy->getPlotDefinitions();

        $this->assertCount(1, $definitions);
        $this->assertArrayHasKey('test_plot', $definitions);
        $plotDef = $definitions['test_plot'];
        $this->assertInstanceOf(PlotDefinition::class, $plotDef);
        $this->assertSame('Test Plot', $plotDef->name);
    }
}

// Test-only concrete implementation
class ConcreteTestStrategy extends AbstractStrategy
{
    #[Input(min: 1)]
    private int $emaPeriod = 20;
    #[Input]
    private bool $someFlag = true;

    protected function defineIndicators(): void
    {
    }

    public function onBar(MultiTimeframeOhlcvSeries $bars): void
    {
    }

    public function testEntry(): void
    {
        $this->entry(DirectionEnum::Long, OrderTypeEnum::Market, '1.5');
    }

    public function testExit(): void
    {
        $this->exit('1.0');
    }

    public function testAddIndicator(string $key, IndicatorInterface $indicator): void
    {
        $this->addIndicator($key, $indicator);
    }

    public function testGetIndicatorSeries(string $indicatorKey, string $seriesKey): Series
    {
        return $this->getIndicatorSeries($indicatorKey, $seriesKey);
    }

    public function testDefinePlot(): void
    {
        $this->definePlot('test_plot', 'Test Plot', true, [new Line()], []);
    }
}
