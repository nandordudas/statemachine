<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Fixtures;

/**
 * State names as class constants: the PHP 7.2 stand-in for a string-backed enum.
 */
final class VideoState
{
    public const STOPPED = 'stopped';
    public const PLAYING = 'playing';
    public const PAUSED = 'paused';

    /** Not a state: only public string constants count. */
    private const SECRET = 'secret';

    /** Not a state either. */
    public const VERSION = 2;

    private function __construct() {}
}
