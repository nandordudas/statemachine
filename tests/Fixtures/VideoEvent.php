<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Fixtures;

final class VideoEvent
{
    public const PLAY = 'PLAY';
    public const PAUSE = 'PAUSE';
    public const STOP = 'STOP';

    private function __construct() {}
}
