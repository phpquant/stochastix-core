<?php

namespace App\Strategy;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Enum\TALibFunctionEnum;
use Stochastix\Domain\Common\Model\OhlcvSeries;
use Stochastix\Domain\Indicator\Model\TALibIndicator;
use Stochastix\Domain\Order\Enum\OrderTypeEnum;
use Stochastix\Domain\Plot\Series\Line;
use Stochastix\Domain\Strategy\AbstractStrategy;
use Stochastix\Domain\Strategy\Attribute\AsStrategy;
use Stochastix\Domain\Strategy\Attribute\Input;

#[AsStrategy(alias: 'sample_strategy', name: 'EMA Crossover')]
final class SampleStrategy extends AbstractStrategy
{
    #[Input(description: 'Period for the fast EMA', min: 1)]
    private int $emaFastPeriod = 12;

    #[Input(description: 'Period for the slow EMA', min: 1)]
    private int $emaSlowPeriod = 26;

    #[Input(description: 'Stop-loss percentage', min: 0.001, max: 0.5)]
    private float $stopLossPercentage = 0.02;

    #[Input(description: 'Stake amount as a percentage of capital', min: 0.001, max: 1.0)]
    private float $stakeAmount = 0.02;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    protected function defineIndicators(): void
    {
        $this
            ->addIndicator(
                'ema_fast',
                new TALibIndicator(TALibFunctionEnum::Ema, ['timePeriod' => $this->emaFastPeriod])
            )
            ->addIndicator(
                'ema_slow',
                new TALibIndicator(TALibFunctionEnum::Ema, ['timePeriod' => $this->emaSlowPeriod])
            )
//            ->addIndicator(
//                'macd',
//                new TALibIndicator(TALibFunctionEnum::Macd, [
//                    'fastPeriod' => 12,
//                    'slowPeriod' => 26,
//                    'signalPeriod' => 9
//                ])
//            )
            ->definePlot(
                indicatorKey: 'ema_fast',
                name: "EMA ($this->emaFastPeriod)",
                overlay: true,
                plots: [
                    new Line(color: '#4e79a7'),
                ]
            )
            ->definePlot(
                indicatorKey: 'ema_slow',
                name: "EMA ($this->emaSlowPeriod)",
                overlay: true,
                plots: [
                    new Line(color: '#f28e2b'),
                ]
            )
//            ->definePlot(
//                indicatorKey: 'macd',
//                name: 'MACD (12, 26, 9)',
//                overlay: false,
//                plots: [
//                    new Line(key: 'macd', color: '#2962FF'),
//                    new Line(key: 'signal', color: '#FF6D00'),
//                    new Histogram(key: 'hist', color: 'rgba(178, 181, 190, 0.5)'),
//                ],
//                annotations: [
//                    new HorizontalLine(value: 0, color: '#787b86', style: HorizontalLineStyleEnum::Dashed)
//                ]
//            )
        ;
    }

    public function onBar(OhlcvSeries $bars): void
    {
        $currentSymbol = $this->context->getCurrentSymbol();
        $currentClose = $bars->close[0];

        if ($currentClose === null) {
            return;
        }

        $fastEma = $this->getIndicatorSeries('ema_fast');
        $slowEma = $this->getIndicatorSeries('ema_slow');

        if ($fastEma[0] === null || $slowEma[0] === null) {
            return;
        }

        $isUpwardCross = $fastEma->crossesOver($slowEma);
        $isDownwardCross = $fastEma->crossesUnder($slowEma);

        $currentCloseStr = (string) $currentClose;

        if (!$this->isInPosition()) {
            $availableCash = $this->orderManager->getPortfolioManager()->getAvailableCash();
            $stakeInCash = bcmul($availableCash, (string) $this->stakeAmount);

            if (bccomp($currentCloseStr, '0') <= 0) {
                $this->logger->warning('Current close price is zero or negative, cannot calculate quantity.');

                return;
            }
            $tradeQuantity = bcdiv($stakeInCash, $currentCloseStr);

            if (bccomp($tradeQuantity, '0.00000001') < 0) {
                $this->logger->info('Calculated trade quantity ({qty}) too small with cash {cash}, skipping trade.', [
                    'qty' => $tradeQuantity,
                    'cash' => $availableCash,
                ]);

                return;
            }

            if ($isUpwardCross) {
                $slFactor = bcsub('1', (string) $this->stopLossPercentage);
                $stopLossPrice = bcmul($currentCloseStr, $slFactor);
                $this->entry(
                    direction: DirectionEnum::Long,
                    orderType: OrderTypeEnum::Market,
                    quantity: $tradeQuantity,
                    stopLossPrice: $stopLossPrice,
                );
            } elseif ($isDownwardCross) {
                $slFactor = bcadd('1', (string) $this->stopLossPercentage);
                $stopLossPrice = bcmul($currentCloseStr, $slFactor);
                $this->entry(
                    direction: DirectionEnum::Short,
                    orderType: OrderTypeEnum::Market,
                    quantity: $tradeQuantity,
                    stopLossPrice: $stopLossPrice,
                );
            }
        } else {
            $openPosition = $this->orderManager->getPortfolioManager()->getOpenPosition($currentSymbol);

            if ($openPosition) {
                if ($openPosition->direction === DirectionEnum::Long && $isDownwardCross) {
                    $this->exit($openPosition->quantity);
                } elseif ($openPosition->direction === DirectionEnum::Short && $isUpwardCross) {
                    $this->exit($openPosition->quantity);
                }
            }
        }
    }
}
