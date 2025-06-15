<?php

namespace Stochastix\Tests\Domain\Backtesting\Repository;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stochastix\Domain\Backtesting\Repository\FileBasedBacktestResultRepository;
use Symfony\Component\Filesystem\Filesystem;

class FileBasedBacktestResultRepositoryTest extends TestCase
{
    private Filesystem $filesystem;
    private string $tempStoragePath;
    private FileBasedBacktestResultRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new Filesystem();
        $this->tempStoragePath = sys_get_temp_dir() . '/stochastix_repo_test_' . uniqid();
        $this->filesystem->mkdir($this->tempStoragePath);
        $this->repository = new FileBasedBacktestResultRepository($this->tempStoragePath, new NullLogger());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->filesystem->remove($this->tempStoragePath);
    }

    public function testGenerateRunId(): void
    {
        $strategyAlias = 'my_test_strategy';
        $runId = $this->repository->generateRunId($strategyAlias);

        $this->assertMatchesRegularExpression(
            '/^\d{8}-\d{6}_my_test_strategy_[a-z0-9]{6}$/',
            $runId
        );
    }

    public function testSaveAndFind(): void
    {
        $runId = '20250101-120000_test_strat_abcdef';
        $results = [
            'status' => 'completed',
            'final_capital' => '12345.67',
            'trades' => [1, 2, 3],
        ];

        $this->repository->save($runId, $results);

        $filePath = $this->tempStoragePath . '/' . $runId . '.json';
        $this->assertFileExists($filePath);

        $foundResults = $this->repository->find($runId);
        $this->assertEquals($results, $foundResults);
    }

    public function testFindReturnsNullForNonExistentRun(): void
    {
        $runId = 'non_existent_run_id';
        $foundResults = $this->repository->find($runId);

        $this->assertNull($foundResults);
    }

    public function testFindAllMetadata(): void
    {
        // Create some dummy result files with different timestamps
        $this->filesystem->touch($this->tempStoragePath . '/20250608-100000_strat_a_111111.json');
        $this->filesystem->touch($this->tempStoragePath . '/20250608-120000_strat_b_222222.json'); // Newest
        $this->filesystem->touch($this->tempStoragePath . '/20250608-090000_strat_c_333333.json'); // Oldest
        $this->filesystem->touch($this->tempStoragePath . '/some_other_file.txt'); // Should be ignored

        $metadata = $this->repository->findAllMetadata();

        $this->assertCount(3, $metadata);

        // Check that the newest run is first
        $this->assertSame('20250608-120000_strat_b_222222', $metadata[0]['runId']);
        $this->assertSame('strat_b', $metadata[0]['strategyAlias']);

        // Check that the oldest run is last
        $this->assertSame('20250608-090000_strat_c_333333', $metadata[2]['runId']);
        $this->assertSame('strat_c', $metadata[2]['strategyAlias']);

        // Check timestamp parsing
        $expectedTimestamp = \DateTime::createFromFormat('Ymd-His', '20250608-100000')->getTimestamp();
        $this->assertSame($expectedTimestamp, $metadata[1]['timestamp']);
    }

    public function testFindAllMetadataHandlesEmptyDirectory(): void
    {
        $metadata = $this->repository->findAllMetadata();
        $this->assertEmpty($metadata);
    }
}
