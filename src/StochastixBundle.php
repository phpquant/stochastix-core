<?php

namespace Stochastix;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class StochastixBundle extends Bundle
{
    public function boot(): void
    {
        parent::boot();

        if ($this->container && $this->container->hasParameter('stochastix.defaults.bc_scale')) {
            $scale = $this->container->getParameter('stochastix.defaults.bc_scale');
            bcscale((int) $scale);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
