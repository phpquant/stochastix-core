<?php

namespace Stochastix\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class StochastixConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('stochastix');

        // @phpstan-ignore-next-line
        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('defaults')
                    ->info('Defines global default parameters for backtests.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('bc_scale')
                            ->defaultValue(8)
                            ->info('The default scale for all bcmath operations.')
                        ->end()
                        ->arrayNode('symbols')
                            ->prototype('scalar')->cannotBeEmpty()->end()
                            ->info('Default trading symbols, e.g., ["BTC/USDT", "ETH/USDT"].')
                        ->end()
                        ->scalarNode('timeframe')
                            ->defaultValue('1d')
                            ->info('Default timeframe for the candles, e.g., "1h", "5m".')
                        ->end()
                        ->scalarNode('start_date')
                             ->defaultNull()
                            ->info('Default start date (YYYY-MM-DD). If null, needs to be provided via CLI or strategy.')
                            ->validate()
                                ->ifTrue(fn ($v) => $v !== null && \DateTimeImmutable::createFromFormat('Y-m-d', $v) === false)
                                ->thenInvalid('Invalid date format, expected YYYY-MM-DD.')
                            ->end()
                        ->end()
                        ->scalarNode('end_date')
                            ->defaultNull()
                            ->info('Default end date (YYYY-MM-DD). If null, needs to be provided via CLI or strategy.')
                             ->validate()
                                ->ifTrue(fn ($v) => $v !== null && \DateTimeImmutable::createFromFormat('Y-m-d', $v) === false)
                                ->thenInvalid('Invalid date format, expected YYYY-MM-DD.')
                            ->end()
                        ->end()
                        ->floatNode('initial_capital')
                            ->defaultValue(10000.0)
                            ->info('Default initial capital.')
                        ->end()
                        ->scalarNode('stake_currency')
                            ->defaultValue('USDT')
                            ->info('Default currency for staking.')
                        ->end()
                        ->scalarNode('stake_amount')
                             ->defaultNull()
                            ->info('Default stake amount (fixed value or percentage).')
                        ->end()
                        ->arrayNode('commission')
                            ->info('Defines the commission model and parameters.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->enumNode('type')
                                    ->values(['percentage', 'fixed_per_trade', 'fixed_per_unit'])
                                    ->defaultValue('percentage')
                                    ->info("Commission type: 'percentage', 'fixed_per_trade', or 'fixed_per_unit'.")
                                ->end()
                                ->floatNode('rate')
                                    ->defaultNull()
                                    ->treatNullLike(0.0)
                                    ->info("Rate for 'percentage' (e.g., 0.001 for 0.1%) or 'fixed_per_unit' (e.g., 0.005 per share/contract).")
                                ->end()
                                ->floatNode('amount')
                                    ->defaultNull()
                                    ->treatNullLike(0.0)
                                    ->info("Amount for 'fixed_per_trade' (e.g., 5 for $5 per trade).")
                                ->end()
                                ->scalarNode('asset')
                                    ->defaultNull()
                                    ->info('The asset in which the commission is charged (e.g., USDT, BTC). Relevant for fixed amounts or if not charged in quote currency.')
                                ->end()
                            ->end()
                            ->validate()
                                ->ifTrue(function ($c) {
                                    if ($c['type'] === 'percentage' && (!isset($c['rate']) || !is_numeric($c['rate']) || $c['rate'] < 0)) {
                                        return true;
                                    }
                                    if ($c['type'] === 'fixed_per_trade' && (!isset($c['amount']) || !is_numeric($c['amount']) || $c['amount'] < 0)) {
                                        return true;
                                    }
                                    if ($c['type'] === 'fixed_per_unit' && (!isset($c['rate']) || !is_numeric($c['rate']) || $c['rate'] < 0)) {
                                        return true;
                                    }

                                    return false;
                                })
                                ->thenInvalid('Invalid commission parameters for the chosen type. "rate" must be a non-negative number for "percentage" and "fixed_per_unit". "amount" must be a non-negative number for "fixed_per_trade".')
                            ->end()
                            ->beforeNormalization()
                                ->ifTrue(fn ($v) => isset($v['type']) && ($v['type'] === 'percentage' || $v['type'] === 'fixed_per_unit') && !array_key_exists('rate', $v))
                                ->then(function ($v) {
                                    $v['rate'] = 0.001; // Default 0.1% if type matches and rate is not set at all

                                    return $v;
                                })
                            ->end()
                             ->beforeNormalization() // Ensure 'amount' is explicitly null if not fixed_per_trade and not set
                                ->ifTrue(fn ($v) => isset($v['type']) && $v['type'] !== 'fixed_per_trade' && !array_key_exists('amount', $v))
                                ->then(function ($v) {
                                    $v['amount'] = null;

                                    return $v;
                                })
                            ->end()
                            ->beforeNormalization() // Ensure 'rate' is explicitly null if fixed_per_trade and not set
                                ->ifTrue(fn ($v) => isset($v['type']) && $v['type'] === 'fixed_per_trade' && !array_key_exists('rate', $v))
                                ->then(function ($v) {
                                    $v['rate'] = null;

                                    return $v;
                                })
                            ->end()
                        ->end()
                        ->arrayNode('data_source')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('exchange_id')
                                    ->defaultValue('binance')
                                    ->info('Default exchange identifier for locating data files.')
                                ->end()
                                ->enumNode('type')->values(['stchx_binary'])->defaultValue('stchx_binary')->end()
                                ->arrayNode('csv_options')->end()
                                ->arrayNode('database_options')->end()
                                ->arrayNode('stchx_binary_options')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('metrics')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('beta')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('rolling_window')->defaultValue(30)->min(2)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
