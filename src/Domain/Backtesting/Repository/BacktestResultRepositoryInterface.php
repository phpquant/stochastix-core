<?php

namespace Stochastix\Domain\Backtesting\Repository;

/**
 * Defines the contract for storing and retrieving backtest run results.
 */
interface BacktestResultRepositoryInterface
{
    /**
     * Saves the results of a backtest run.
     *
     * @param string               $runId   the unique ID of the backtest run
     * @param array<string, mixed> $results the results array from the Backtester service
     */
    public function save(string $runId, array $results): void;

    /**
     * Finds the results of a backtest run by its ID.
     *
     * @param string $runId the unique ID of the backtest run
     *
     * @return array<string, mixed>|null the results array, or null if not found
     */
    public function find(string $runId): ?array;

    /**
     * Deletes a backtest run and all its associated data files.
     *
     * @param string $runId the unique ID of the backtest run
     *
     * @return bool true if any files were deleted, false if no files were found
     */
    public function delete(string $runId): bool;
}
