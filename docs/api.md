# Stochastix API Documentation

This document outlines the RESTful API endpoints provided by the `stochastix-core` bundle.

## General Information
* **Base Path:** `/api`
* **Authentication:** All endpoints are currently public and do not require authentication, as the application is designed for local/offline use.
* **Data Format:** All request and response bodies are `application/json`.

## Endpoints

### Strategies

* **`GET /api/strategies`**
    * **Description:** Fetches a list of all available trading strategies and their configurable input parameters. This is used to dynamically build the "New Backtest" form in the UI.
    * **Requires:** None
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        [
          {
            "alias": "sample_strategy",
            "name": "Minimal EMA Crossover",
            "description": "A simple strategy based on Exponential Moving Average crossovers.",
            "inputs": [
              {
                "name": "emaShortPeriod",
                "description": "Period for the short EMA",
                "type": "integer",
                "defaultValue": 12,
                "min": 1,
                "max": null,
                "choices": null
              },
              {
                "name": "emaLongPeriod",
                "description": "Period for the long EMA",
                "type": "integer",
                "defaultValue": 26,
                "min": 1,
                "max": null,
                "choices": null
              }
            ]
          }
        ]
        ```
    * **Error Responses:**
        * `500 Internal Server Error`: If an error occurs while scanning for strategies.

---
### Market Data

* **`GET /api/data/exchanges`**
    * **Description:** Retrieves a sorted list of all exchange IDs supported by the backend (via the CCXT library).
    * **Requires:** None
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
      ```json
      [
          "ace",
          "alpaca",
          "ascendex",
          "bequant",
          "bigone",
          "binance",
          "binancecoinm",
          "binanceus",
          "binanceusdm",
          "bingx"
      ]
      ```

* **`GET /api/data-availability`**
    * **Description:** Scans the server for available market data (`.stchx` files) and returns a manifest detailing available symbols, their timeframes, and the start/end dates for each dataset.
    * **Requires:** None
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        [
          {
            "symbol": "ETH/USDT",
            "exchange": "okx",
            "timeframes": [
              {
                "timeframe": "5m",
                "startDate": "2025-01-01T00:00:00Z",
                "endDate": "2025-04-01T00:20:00Z",
                "recordCount": 25925
              },
              {
                "timeframe": "1h",
                "startDate": "2024-01-01T00:00:00Z",
                "endDate": "2025-05-01T00:00:00Z",
                "recordCount": 11641
              }
            ]
          }
        ]
        ```
    * **Error Responses:**
        * `500 Internal Server Error`: If an error occurs during the filesystem scan.

* **`POST /api/data/download`**
    * **Description:** Queues a new market data download for asynchronous execution. Returns a unique `jobId` immediately, which can be used to listen for real-time progress updates via Mercure.
    * **Requires:** None
    * **Request Body:** (`Stochastix\Domain\Data\Dto\DownloadRequestDto`)
        * `exchangeId` (string, required): The exchange ID (e.g., "binance", "okx").
        * `symbol` (string, required): The trading symbol (e.g., "BTC/USDT").
        * `timeframe` (string, required): The timeframe to download (e.g., "1h", "1d").
        * `startDate` (string, required, `YYYY-MM-DD`): The start date for the download.
        * `endDate` (string, required, `YYYY-MM-DD`): The end date for the download.
    * **Example Request Body:**
        ```json
        {
          "exchangeId": "binance",
          "symbol": "BTC/USDT",
          "timeframe": "1d",
          "startDate": "2023-01-01",
          "endDate": "2024-01-01"
        }
        ```
    * **Success Response:** `202 Accepted`
    * **Example Success Response Body:**
        ```json
        {
          "status": "queued",
          "jobId": "download_666c1e5a7b8d9"
        }
        ```
    * **Error Responses:**
        * `400 Bad Request`: Invalid input (e.g., validation errors in the request body, end date before start date).
        * `500 Internal Server Error`: If the download message could not be dispatched to the queue.

* **`DELETE /api/data/download/{jobId}`**
    * **Description:** Requests the cancellation of a running download job. The cancellation may take a few moments to take effect, as it is checked between data chunk fetches.
    * **Requires:** None
    * **URL Parameters:**
        * `jobId` (string, required): The unique ID of the download job to cancel.
    * **Request Body:** None
    * **Success Response:** `202 Accepted`
    * **Example Success Response Body:**
        ```json
        {
          "status": "cancellation_requested",
          "jobId": "download_666c1e5a7b8d9"
        }
        ```
    * **Error Responses:** None.

* **`GET /api/data/inspect/{exchangeId}/{symbol}/{timeframe}`**
    * **Description:** Inspects a specific market data file (`.stchx`). Returns the file's header metadata, a sample of the first and last records, and a full data consistency validation report (checking for gaps, duplicates, and out-of-order records).
    * **Requires:** None
    * **URL Parameters:**
        * `exchangeId` (string, required): The exchange ID of the data file (e.g., "binance").
        * `symbol` (string, required): The trading symbol. Use a dash (`-`) as a separator for URL safety (e.g., "BTC-USDT").
        * `timeframe` (string, required): The timeframe of the data file (e.g., "1h", "1d").
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        {
          "filePath": "/app/data/market/binance/BTC_USDT/1d.stchx",
          "fileSize": 48064,
          "header": {
            "magic": "STCHXBF1",
            "version": 1,
            "headerLength": 64,
            "recordLength": 48,
            "tsFormat": 1,
            "ohlcvFormat": 1,
            "numRecords": 1000,
            "symbol": "BTC/USDT",
            "timeframe": "1d"
          },
          "sample": {
            "head": [
              {
                "timestamp": 1672531200,
                "utc": "2023-01-01 00:00:00",
                "open": 16541.77,
                "high": 16630.43,
                "low": 16499.0,
                "close": 16603.24,
                "volume": 145339.71
              }
            ],
            "tail": [
              {
                "timestamp": 1758998400,
                "utc": "2025-09-26 00:00:00",
                "open": 85000.0,
                "high": 86000.0,
                "low": 84000.0,
                "close": 85500.0,
                "volume": 200000.0
              }
            ]
          },
          "validation": {
            "status": "passed",
            "message": "Data appears consistent.",
            "gaps": [],
            "duplicates": [],
            "outOfOrder": []
          }
        }
        ```
    * **Error Responses:**
        * `404 Not Found`: If the requested data file does not exist.
        * `500 Internal Server Error`: For any other processing errors.


* **`GET /api/data/symbols/{exchangeId}`**
    * **Description:** Fetches a list of all available and active futures/swap symbols for a given exchange. This can be used to populate a symbol selection UI.
    * **Requires:** None
    * **URL Parameters:**
        * `exchangeId` (string, required): The exchange ID to query (e.g., "binance", "okx").
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
      ```json
      [
          "1000PEPE/USDT:USDT",
          "ADA/USDT:USDT",
          "AVAX/USDT:USDT",
          "BCH/USDT:USDT",
          "BTC/USDT:USDT",
          "DOGE/USDT:USDT",
          "DOT/USDT:USDT",
          "ETH/USDT:USDT",
          "LINK/USDT:USDT",
          "LTC/USDT:USDT",
          "MATIC/USDT:USDT",
          "SOL/USDT:USDT",
          "XRP/USDT:USDT"
      ]
      ```
    * **Error Responses:**
        * `400 Bad Request`: If the exchange ID is not supported or if there's an error communicating with the exchange.

---
### Backtesting

* **`POST /api/backtests`**
    * **Description:** Queues a new backtest run for asynchronous execution. Returns a unique `runId` immediately, which can be used to listen for real-time progress updates via Mercure.
    * **Requires:** None
    * **Request Body:** (`Stochastix\Domain\Backtesting\Dto\LaunchBacktestRequestDto`)
        * `strategyAlias` (string, required): The alias of the strategy to run.
        * `symbols` (string[], required): An array of symbols to run the backtest on (currently UI sends one).
        * `timeframe` (string, required): The timeframe to use (e.g., "5m", "1d").
        * `initialCapital` (string, required): The starting capital for the portfolio, as a string.
        * `dataSourceExchangeId` (string, optional): The exchange to use for data. If omitted, the server's default is used.
        * `startDate` (string, optional, `YYYY-MM-DD`): The start date. If `null`, the backtest starts from the beginning of available data.
        * `endDate` (string, optional, `YYYY-MM-DD`): The end date. If `null`, the backtest runs to the end of available data.
        * `inputs` (object, optional): A key-value map of the strategy-specific inputs.
        * `commissionConfig` (object, optional): Configuration for commission.
    * **Example Request Body:**
        ```json
        {
          "strategyAlias": "sample_strategy",
          "symbols": ["ETH/USDT"],
          "timeframe": "5m",
          "initialCapital": "50000",
          "dataSourceExchangeId": "okx",
          "startDate": "2025-02-01",
          "endDate": "2025-03-01",
          "inputs": {
            "emaShortPeriod": 10,
            "emaLongPeriod": 21
          }
        }
        ```
    * **Success Response:** `202 Accepted`
    * **Example Success Response Body:**
        ```json
        {
          "status": "queued",
          "backtestRunId": "btrun_6662d5119b91c7.73453396"
        }
        ```
    * **Error Responses:**
        * `400 Bad Request`: Invalid input (validation errors in the request body).
        * `500 Internal Server Error`: If the message could not be dispatched.

* **`GET /api/backtests`**
    * **Description:** Retrieves a list of all previously completed and saved backtest runs, sorted with the most recent first. Provides metadata parsed from filenames.
    * **Requires:** None
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        [
            {
                "runId": "20250607-083000_sample_strategy_a1b2c3",
                "timestamp": 1749285000,
                "strategyAlias": "sample_strategy"
            },
            {
                "runId": "20250606-140037_sample_strategy_d4e5f6",
                "timestamp": 1749201637,
                "strategyAlias": "sample_strategy"
            }
        ]
        ```
    * **Error Responses:**
        * `500 Internal Server Error`.

* **`GET /api/backtests/{runId}`**
    * **Description:** Fetches the full, detailed result JSON file for a single completed backtest run. The response includes the backtest configuration, the full trade log, a list of any trades still open at the end of the run, and a comprehensive, pre-calculated statistics object.
    * **Requires:** None
    * **URL Parameters:**
        * `runId` (string, required): The unique ID of the backtest run.
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        {
            "status": "Backtest completed. Processed 8640 bars across 1 symbols.",
            "config": {
                "strategyAlias": "sample_strategy",
                "strategyClass": "App\\Strategy\\SampleStrategy",
                "symbols": ["ETH/USDT"],
                "timeframe": "5m",
                "startDate": "2025-02-01T00:00:00+00:00",
                "endDate": "2025-03-01T00:00:00+00:00",
                "initialCapital": "50000",
                "commissionConfig": { "type": "percentage", "rate": "0.001" },
                "strategyInputs": { "emaShortPeriod": 10, "emaLongPeriod": 21 }
            },
            "finalCapital": "51260.75",
            "closedTrades": [
                {
                    "tradeNumber": 1,
                    "symbol": "ETH/USDT",
                    "direction": "long",
                    "entryPrice": "3000.00",
                    "exitPrice": "3150.50",
                    "quantity": "0.83333333",
                    "entryTime": "2025-02-02 10:00:00",
                    "exitTime": "2025-02-03 14:20:00",
                    "pnl": "124.87",
                    "entryCommission": "1.25",
                    "exitCommission": "1.31",
                    "enterTags": ["ema_crossover_long"],
                    "exitTags": ["exit_signal"]
                }
            ],
            "openPositions": [
                {
                    "symbol": "ETH/USDT",
                    "direction": "long",
                    "quantity": "0.50000000",
                    "entryPrice": "3100.00000",
                    "entryTime": "2025-02-28 11:30:00",
                    "currentPrice": "3120.00000",
                    "unrealizedPnl": "10.00"
                }
            ],
            "statistics": {
                "pairStats": [
                    {
                        "label": "ETH/USDT",
                        "trades": 48,
                        "averageProfitPercentage": 0.051,
                        "totalProfit": 1250.75,
                        "totalProfitPercentage": 2.5,
                        "avgDurationMin": 180,
                        "wins": 30,
                        "draws": 0,
                        "losses": 18
                    }
                ],
                "enterTagStats": [
                    {
                        "label": "ema_crossover_long",
                        "trades": 24,
                        "averageProfitPercentage": 0.12,
                        "totalProfit": 800.50,
                        "totalProfitPercentage": 1.6,
                        "avgDurationMin": 200,
                        "wins": 20,
                        "draws": 0,
                        "losses": 4
                    }
                ],
                "exitTagStats": [
                    {
                        "label": "exit_signal",
                        "trades": 30,
                        "averageProfitPercentage": 0.08,
                        "totalProfit": 950.00,
                        "totalProfitPercentage": 1.9,
                        "avgDurationMin": 190,
                        "wins": 25,
                        "draws": 0,
                        "losses": 5
                    },
                    {
                        "label": "stop_loss",
                        "trades": 18,
                        "averageProfitPercentage": -0.02,
                        "totalProfit": -300.75,
                        "totalProfitPercentage": -0.6,
                        "avgDurationMin": 160,
                        "wins": 5,
                        "draws": 0,
                        "losses": 13
                    }
                ],
                "summaryMetrics": {
                    "backtestingFrom": "2025-02-01 00:00:00",
                    "backtestingTo": "2025-03-01 00:00:00",
                    "maxOpenTrades": 1,
                    "totalTrades": 48,
                    "dailyAvgTrades": 1.71,
                    "startingBalance": 50000,
                    "finalBalance": 51260.75,
                    "balanceCurrency": "USDT",
                    "absProfit": 1260.75,
                    "totalProfitPercentage": 2.52,
                    "profitFactor": 2.1,
                    "sharpe": 1.89,
                    "sortino": 2.4,
                    "calmar": 1.2,
                    "cagrPercentage": 34.5,
                    "expectancy": 26.05,
                    "expectancyRatio": 1.5,
                    "avgDurationWinnersMin": 200,
                    "avgDurationLosersMin": 160,
                    "maxConsecutiveWins": 5,
                    "maxConsecutiveLosses": 3,
                    "maxAccountUnderwaterPercentage": 1.5,
                    "absoluteDrawdown": 750.00,
                    "marketChangePercentage": 5.2
                }
            }
        }
        ```
    * **Error Responses:**
        * `404 Not Found`: If no result file exists for the given `runId`.
        * `500 Internal Server Error`.

---

### Get Chart Data

* **`GET /api/chart-data/{runId}`**
    * **Description:** Fetches the OHLCV (candlestick) data, closed trades, open positions, and indicator data required to render a chart for a specific backtest run. Supports pagination for handling large datasets.
    * **Requires:** None
    * **URL Parameters:**
        * `runId` (string, required): The unique ID of the backtest run.
    * **Query Parameters:**
        * `fromTimestamp` (integer, optional): Unix timestamp for the start of the desired data range.
        * `toTimestamp` (integer, optional): Unix timestamp for the end of the desired data range.
        * `countback` (integer, optional): The number of candles to fetch counting back from `toTimestamp` (or from the end of the backtest if `toTimestamp` is not provided).
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        {
            "ohlcv": [
                { "time": 1738454400, "open": 2999.5, "high": 3005.0, "low": 2998.0, "close": 3000.0 },
                { "time": 1738454700, "open": 3000.0, "high": 3008.0, "low": 2999.0, "close": 3007.5 }
            ],
            "trades": [
                {
                    "direction": "long",
                    "quantity": "1.000000",
                    "entryTime": 1738368000,
                    "entryPrice": "2950.00",
                    "exitTime": 1738454400,
                    "exitPrice": "3000.00",
                    "pnl": "50.00"
                }
            ],
            "openPositions": [
                {
                    "direction": "short",
                    "quantity": "0.5",
                    "entryTime": 1738454700,
                    "entryPrice": "3007.50",
                    "currentPrice": "3007.50",
                    "unrealizedPnl": "0.00"
                }
            ],
            "indicators": {
                "ema_fast": {
                    "metadata": {
                        "name": "Fast EMA",
                        "overlay": true,
                        "plots": [{"key": "value", "type": "line", "color": null}],
                        "annotations": []
                    },
                    "data": {
                        "value": [
                            { "time": 1738454400, "value": 2998.5 },
                            { "time": 1738454700, "value": 3001.0 }
                        ]
                    }
                }
            }
        }
        ```
    * **Error Responses:**
        * `404 Not Found`: If the `runId` is invalid or if the required market data file is missing.
        * `400 Bad Request`: If the backtest configuration is malformed or query parameters are invalid.
        * `500 Internal Server Error`.

---

### Plot Endpoints

This family of endpoints provides access to the raw, high-resolution time-series data calculated at the end of a backtest run, suitable for rendering detailed performance charts.

* **`GET /api/plots/{metric}/{runId}`**
    * **Description:** Fetches a specific time-series data plot (like the mark-to-market equity curve) for a given backtest run.
    * **Requires:** None
    * **URL Parameters:**
        * `metric` (string, required): The name of the metric series to fetch. Currently supported: `equity`.
        * `runId` (string, required): The unique ID of the backtest run.
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body (for `/api/plots/equity/some_run_id`):**
        ```json
        {
            "data": [
                { "time": 1704067200, "value": 10000.0 },
                { "time": 1704153600, "value": 10000.0 },
                { "time": 1704240000, "value": 9920.0 },
                { "time": 1704326400, "value": 10040.0 }
            ]
        }
        ```
    * **Error Responses:**
        * `404 Not Found`: If the `runId` is invalid or if the requested `{metric}` plot does not exist for that run.
        * `500 Internal Server Error`.

---

### Chart Exploration & Layouts

This section details the endpoints for managing persistent chart layouts and fetching on-demand indicator data for interactive analysis.

* **`GET /api/charts/available-indicators`**
    * **Description:** Fetches a categorized list of all indicators available for plotting. This includes "Built-in" (from the TA-Lib library) and "Custom" (user-defined PHP classes) indicators.
    * **Requires:** None
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        {
          "builtIn": [
            {
              "name": "Simple Moving Average",
              "description": null,
              "alias": "sma",
              "type": "talib",
              "inputs": [{"name": "timePeriod", "type": "integer"}]
            },
            {
              "name": "Relative Strength Index",
              "description": null,
              "alias": "rsi",
              "type": "talib",
              "inputs": [{"name": "timePeriod", "type": "integer"}]
            }
          ],
          "custom": [
            {
                "name": "My Custom Volatility",
                "description": "A custom volatility indicator.",
                "alias": "App\\Indicator\\MyVolatilityIndicator",
                "type": "custom",
                "inputs": [
                    { "name": "period", "description": "Lookback period", "type": "integer", "defaultValue": 14 }
                ]
            }
          ]
        }
        ```
    * **Error Responses:**
        * `500 Internal Server Error`: If an error occurs during indicator discovery.

* **`POST /api/charts/indicators`**
    * **Description:** The core on-demand calculation endpoint. It takes a market context (symbol, timeframe) and a list of indicator configurations, and returns the OHLCV data for the requested window along with the calculated indicator series data.
    * **Requires:** None
    * **Request Body:** (`Stochastix\Domain\Chart\Dto\ChartIndicatorRequestDto`)
    * **Example Request Body:**
        ```json
        {
          "exchangeId": "binance",
          "symbol": "BTC/USDT",
          "timeframe": "1h",
          "countback": 500,
          "indicators": [
            {
              "key": "ema_fast",
              "type": "talib",
              "function": "ema",
              "params": { "timePeriod": 10 },
              "source": "close"
            },
            {
              "key": "rsi_14",
              "type": "talib",
              "function": "rsi",
              "params": { "timePeriod": 14 },
              "source": "close"
            }
          ]
        }
        ```
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        {
          "ohlcv": [
            { "time": 1738454400, "open": 3000.0, "high": 3005.0, "low": 2998.0, "close": 3000.0 }
          ],
          "indicators": {
            "ema_fast": {
              "value": [{ "time": 1738454400, "value": 2995.5 }]
            },
            "rsi_14": {
              "value": [{ "time": 1738454400, "value": 55.45 }]
            }
          }
        }
        ```
    * **Error Responses:**
        * `404 Not Found`: If the underlying market data file (`.stchx`) does not exist.
        * `500 Internal Server Error`: For any calculation errors.

* **`POST /api/charts/layouts`**
    * **Description:** Creates and saves a new chart layout.
    * **Requires:** None
    * **Request Body:** (`Stochastix\Domain\Chart\Dto\SaveLayoutRequestDto`)
    * **Example Request Body:**
        ```json
        {
            "name": "My ETH Volatility Layout",
            "symbol": "ETH/USDT",
            "timeframe": "4h",
            "indicators": [
                { "key": "bbands_20", "type": "talib", "function": "bbands", "params": { "timePeriod": 20, "nbDevUp": 2, "nbDevDn": 2 } },
                { "key": "atr_14", "type": "talib", "function": "atr", "params": { "timePeriod": 14 } }
            ]
        }
        ```
    * **Success Response:** `201 Created`
    * **Success Response Body:** The full, newly created `ChartLayout` entity, including its new ID and timestamps.

* **`GET /api/charts/layouts`**
    * **Description:** Retrieves a list of all saved chart layouts, sorted by most recently updated.
    * **Requires:** None
    * **Request Body:** None
    * **Success Response:** `200 OK`
    * **Example Success Response Body:**
        ```json
        [
            {
                "id": "01J0Z7X2J8Y4Z6T8A9E1G4H2J8",
                "name": "My BTC Daily Setup",
                "symbol": "BTC/USDT",
                "timeframe": "1d",
                "indicators": [
                    {
                        "key": "ema_fast",
                        "type": "talib",
                        "function": "ema",
                        "params": {
                            "timePeriod": 12
                        },
                        "source": "close"
                    },
                    {
                        "key": "rsi_main",
                        "type": "talib",
                        "function": "rsi",
                        "params": {
                            "timePeriod": 14
                        },
                        "source": "close"
                    }
                ],
                "createdAt": "2025-06-20T14:30:00+00:00",
                "updatedAt": "2025-06-20T15:00:00+00:00"
            }
        ]
        ```

* **`GET /api/charts/layouts/{id}`**
    * **Description:** Retrieves the full configuration of a single chart layout by its ID.
    * **URL Parameters:**
        * `id` (string, required): The ULID of the chart layout.
    * **Success Response:** `200 OK`
    * **Success Response Body:** A single `ChartLayout` object.

* **`PUT /api/charts/layouts/{id}`**
    * **Description:** Updates an existing chart layout. The entire new layout configuration must be sent.
    * **URL Parameters:**
        * `id` (string, required): The ULID of the chart layout to update.
    * **Request Body:** (`Stochastix\Domain\Chart\Dto\SaveLayoutRequestDto`)
    * **Success Response:** `200 OK`
    * **Success Response Body:** The full, updated `ChartLayout` object.
    * **Error Responses:**
        * `404 Not Found`: If the layout ID does not exist.

* **`DELETE /api/charts/layouts/{id}`**
    * **Description:** Deletes a chart layout.
    * **URL Parameters:**
        * `id` (string, required): The ULID of the chart layout to delete.
    * **Success Response:** `240 No Content`
    * **Error Responses:**
        * `404 Not Found`: If the layout ID does not exist.
