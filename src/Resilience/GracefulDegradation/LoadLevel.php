<?php

declare(strict_types=1);

namespace Patterns\Resilience\GracefulDegradation;

enum LoadLevel: int
{
    case Normal = 0;
    case Elevated = 1;
    case High = 2;
    case Critical = 3;
}
