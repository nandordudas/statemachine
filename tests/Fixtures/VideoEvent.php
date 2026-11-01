<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Fixtures;

enum VideoEvent: string
{
    case Play = 'PLAY';
    case Stop = 'STOP';
    case Pause = 'PAUSE';
    case Resume = 'RESUME';
}
