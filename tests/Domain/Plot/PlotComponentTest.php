<?php

namespace Stochastix\Tests\Domain\Plot;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Plot\Annotation\HorizontalLine;
use Stochastix\Domain\Plot\Enum\HorizontalLineStyleEnum;
use Stochastix\Domain\Plot\PlotDefinition;
use Stochastix\Domain\Plot\Series\Histogram;
use Stochastix\Domain\Plot\Series\Line;

class PlotComponentTest extends TestCase
{
    public function testLineToArray(): void
    {
        $line = new Line('close_price', '#ff0000');
        $expected = [
            'key' => 'close_price',
            'type' => 'line',
            'color' => '#ff0000',
        ];
        $this->assertEquals($expected, $line->toArray());
    }

    public function testHistogramToArray(): void
    {
        $histogram = new Histogram('volume_data', '#00ff00');
        $expected = [
            'key' => 'volume_data',
            'type' => 'histogram',
            'color' => '#00ff00',
        ];
        $this->assertEquals($expected, $histogram->toArray());
    }

    public function testHorizontalLineToArray(): void
    {
        $hline = new HorizontalLine(80.0, '#0000ff', HorizontalLineStyleEnum::Dashed, 2);
        $expected = [
            'type' => 'horizontal_line',
            'value' => 80.0,
            'color' => '#0000ff',
            'style' => 'dashed',
            'width' => 2,
        ];
        $this->assertEquals($expected, $hline->toArray());
    }

    public function testPlotDefinitionIsCorrectlyConstructed(): void
    {
        $plots = [new Line()];
        $annotations = [new HorizontalLine(50)];

        $definition = new PlotDefinition(
            indicatorKey: 'my_indicator',
            name: 'My Indicator',
            overlay: true,
            plots: $plots,
            annotations: $annotations
        );

        $this->assertSame('my_indicator', $definition->indicatorKey);
        $this->assertSame('My Indicator', $definition->name);
        $this->assertTrue($definition->overlay);
        $this->assertSame($plots, $definition->plots);
        $this->assertSame($annotations, $definition->annotations);
    }
}
