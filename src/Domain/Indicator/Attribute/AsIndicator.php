<?php

namespace Stochastix\Domain\Indicator\Attribute;

/**
 * An attribute to mark a class as a custom, user-defined indicator,
 * making it discoverable by the IndicatorDiscoveryService.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsIndicator
{
    /**
     * @param string $name A user-friendly name for the indicator (e.g., "My Custom Oscillator").
     * @param string|null $description A brief description of what the indicator does.
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {
    }
}
