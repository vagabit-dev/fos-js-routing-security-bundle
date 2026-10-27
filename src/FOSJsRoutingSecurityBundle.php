<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class FOSJsRoutingSecurityBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
