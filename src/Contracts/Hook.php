<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

use Dudas\StateMachine\TransitionContext;

interface Hook
{
    public function handle(TransitionContext $context): void;
}
