<?php

namespace Stochastix\Domain\Chart\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Stochastix\Domain\Chart\Repository\ChartLayoutRepository;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: ChartLayoutRepository::class)]
#[ORM\Table(name: 'chart_layout')]
class ChartLayout
{
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.ulid_generator')]
    private ?Ulid $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $symbol;

    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $timeframe;

    /**
     * @var array<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $indicators = [];

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): self
    {
        $this->symbol = $symbol;

        return $this;
    }

    public function getTimeframe(): string
    {
        return $this->timeframe;
    }

    public function setTimeframe(string $timeframe): self
    {
        $this->timeframe = $timeframe;

        return $this;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getIndicators(): array
    {
        return $this->indicators;
    }

    /**
     * @param array<array<string, mixed>> $indicators
     */
    public function setIndicators(array $indicators): self
    {
        $this->indicators = $indicators;

        return $this;
    }
}
