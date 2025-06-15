# Stochastix

[![Stochastix CI](https://github.com/phpquant/stochastix-core/actions/workflows/ci.yaml/badge.svg)](https://github.com/phpquant/stochastix-core/actions/workflows/ci.yaml)

Stochastix is a high-performance, event-driven quantitative trading backtesting engine built with PHP and Symfony. It provides a modular and extensible framework for developing, testing, and analyzing algorithmic trading strategies.

## Key Features

* **Event-Driven Architecture:** Built for performance and realism.
* **Advanced Order Types:** Supports Market, Limit, and Stop orders with a full pending order book.
* **Multi-Timeframe Engine:** Develop strategies that analyze data across multiple timeframes simultaneously.
* **Extensible Metrics:** A full suite of performance metrics (Sharpe, Sortino, Calmar, etc.) with a dependency-aware calculation engine.
* **High-Performance Storage:** Custom binary file formats for efficient market data storage and retrieval.
* **User Interface:** Modern web interface to download market data, launch backtest and analyze results.
* **REST API:** A complete API for building custom interfaces, including real-time progress updates via Mercure.

## Documentation

For the latest official documentation, visit the [Stochastix Documentation](https://phpquant.github.io/stochastix-docs).

## Installation

This project is a Symfony bundle. To install it in your application:

1.  **Require the bundle with Composer:**
    ```bash
    composer require stochastix/core
    ```

2.  **Enable the bundle (if not using Symfony Flex):**
    In `config/bundles.php`, add:
    ```php
    return [
        // ...
        Stochastix\StochastixBundle::class => ['all' => true],
    ];
    ```

## Contributing

We welcome contributions from the community! Please read our [**Contributing Guide**](CONTRIBUTING.md) to learn about our development process, how to propose bugfixes and improvements, and how to build and test your changes.
