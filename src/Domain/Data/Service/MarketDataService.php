<?php

namespace Stochastix\Domain\Data\Service;

use ccxt\Exchange;
use Psr\Cache\InvalidArgumentException;
use Stochastix\Domain\Data\Exception\ExchangeException;
use Stochastix\Domain\Data\Service\Exchange\ExchangeFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class MarketDataService
{
    public function __construct(
        private ExchangeFactory $exchangeFactory,
        private CacheInterface $cache
    ) {
    }

    /**
     * @return string[]
     *
     * @throws ExchangeException
     * @throws InvalidArgumentException
     */
    public function getFuturesSymbols(string $exchangeId): array
    {
        $cacheKey = 'symbols.futures.' . preg_replace('/[^a-zA-Z0-9_.]/', '_', $exchangeId);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($exchangeId) {
            // Set the cache to expire after one week
            $item->expiresAfter(new \DateInterval('P7D'));

            try {
                $exchange = $this->exchangeFactory->create($exchangeId);
                $markets = $exchange->loadMarkets();

                $uniqueSymbols = [];
                foreach ($markets as $market) {
                    if (isset($market['active'], $market['type'], $market['base'], $market['quote']) && $market['active'] === true) {
                        if ($market['type'] === 'swap' || $market['type'] === 'future') {
                            $symbol = $market['base'] . '/' . $market['quote'];
                            $uniqueSymbols[$symbol] = true; // Use associative array keys for automatic deduplication
                        }
                    }
                }

                $futuresSymbols = array_keys($uniqueSymbols);
                sort($futuresSymbols);

                return $futuresSymbols;
            } catch (\Throwable $e) {
                // Re-throw as a domain-specific exception so it can be caught by the controller
                throw new ExchangeException("Failed to fetch symbols for exchange '{$exchangeId}': " . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * @return string[]
     */
    public function getExchanges(): array
    {
        $exchanges = Exchange::$exchanges;
        sort($exchanges);

        return $exchanges;
    }
}
