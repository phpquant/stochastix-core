<?php

namespace Stochastix\Domain\Data\Service\Exchange;

use ccxt\Exchange;
use Stochastix\Domain\Data\Exception\ExchangeException;

final class ExchangeFactory
{
    /**
     * @var array<string, Exchange>
     */
    private array $instances = [];

    /**
     * @throws ExchangeException
     */
    public function create(string $exchangeId): Exchange
    {
        if (isset($this->instances[$exchangeId])) {
            return $this->instances[$exchangeId];
        }

        if (!in_array($exchangeId, Exchange::$exchanges, true)) {
            throw new ExchangeException(sprintf('Exchange "%s" is not supported by CCXT.', $exchangeId));
        }

        $class = "\\ccxt\\{$exchangeId}";
        $this->instances[$exchangeId] = new $class();

        return $this->instances[$exchangeId];
    }
}
