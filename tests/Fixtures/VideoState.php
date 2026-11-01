<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Fixtures;

enum VideoState: string
{
    case Playing = 'playing';
    case Stopped = 'stopped';
    case Paused = 'paused';
}
