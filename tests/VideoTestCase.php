<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Transition;
use PHPUnit\Framework\TestCase;

abstract class VideoTestCase extends TestCase
{
    /**
     * @param list<Transition> $extra
     */
    protected function video(string $initial = 'stopped', array $extra = []): Definition
    {
        return Definition::builder()
            ->initial($initial)
            ->states('playing', 'stopped', 'paused')
            ->transition(
                Transition::on('PLAY', ['stopped', 'paused'], 'playing'),
                Transition::on('STOP', 'playing', 'stopped'),
                Transition::on('PAUSE', 'playing', 'paused'),
                ...$extra,
            )
            ->build();
    }
}
