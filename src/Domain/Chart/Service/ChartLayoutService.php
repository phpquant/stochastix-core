<?php

namespace Stochastix\Domain\Chart\Service;

use Stochastix\Domain\Chart\Dto\IndicatorRequest;
use Stochastix\Domain\Chart\Dto\SaveLayoutRequestDto;
use Stochastix\Domain\Chart\Entity\ChartLayout;
use Stochastix\Domain\Chart\Repository\ChartLayoutRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Ulid;

/**
 * Service class for handling business logic related to ChartLayout entities.
 * It acts as an intermediary between controllers and the repository.
 */
final readonly class ChartLayoutService
{
    public function __construct(private ChartLayoutRepository $repository)
    {
    }

    /**
     * Creates a new ChartLayout entity from a DTO.
     */
    public function createLayout(SaveLayoutRequestDto $dto): ChartLayout
    {
        $layout = $this->mapDtoToEntity($dto, new ChartLayout());
        $this->repository->save($layout, true);

        return $layout;
    }

    /**
     * Updates an existing ChartLayout entity from a DTO.
     */
    public function updateLayout(string $id, SaveLayoutRequestDto $dto): ChartLayout
    {
        $layout = $this->findLayoutById($id);
        $this->mapDtoToEntity($dto, $layout);
        $this->repository->save($layout, true);

        return $layout;
    }

    /**
     * Deletes a ChartLayout entity by its ID.
     */
    public function deleteLayout(string $id): void
    {
        $layout = $this->findLayoutById($id);
        $this->repository->remove($layout, true);
    }

    /**
     * Finds a single layout by its ULID.
     *
     * @throws NotFoundHttpException if the layout does not exist.
     */
    public function findLayoutById(string $id): ChartLayout
    {
        if (!Ulid::isValid($id)) {
            throw new NotFoundHttpException('Invalid layout ID format.');
        }

        $layout = $this->repository->find($id);

        if (!$layout) {
            throw new NotFoundHttpException('Chart layout not found.');
        }

        return $layout;
    }

    /**
     * Maps data from the request DTO to the Doctrine entity.
     */
    private function mapDtoToEntity(SaveLayoutRequestDto $dto, ChartLayout $layout): ChartLayout
    {
        $layout->setName($dto->name);
        $layout->setSymbol($dto->symbol);
        $layout->setTimeframe($dto->timeframe);

        // Convert array of IndicatorRequest objects to a plain array for JSON serialization.
        $indicatorConfig = array_map(
            static fn (IndicatorRequest $indicator) => (array) $indicator,
            $dto->indicators
        );

        $layout->setIndicators($indicatorConfig);

        return $layout;
    }
}

