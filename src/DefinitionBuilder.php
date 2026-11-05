<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * Fluent assembly of a {@see Definition}:
 *
 *     $definition = Definition::builder()
 *         ->initial(VideoState::STOPPED)
 *         ->states(...Name::constantsOf(VideoState::class))
 *         ->transition(Transition::on(VideoEvent::PLAY, [VideoState::STOPPED, VideoState::PAUSED], VideoState::PLAYING))
 *         ->build();
 */
final class DefinitionBuilder
{
    /** @var string|null */
    private $initial;

    /** @var string[] */
    private $states = [];

    /** @var Transition[] */
    private $transitions = [];

    /** @var array<string, array> */
    private $onEnter = [];

    /** @var array<string, array> */
    private $onExit = [];

    public function initial(string $state): self
    {
        $this->initial = $state;

        return $this;
    }

    public function states(string ...$states): self
    {
        $this->states = array_merge($this->states, $states);

        return $this;
    }

    public function transition(Transition ...$transitions): self
    {
        $this->transitions = array_merge($this->transitions, $transitions);

        return $this;
    }

    /**
     * Hooks run once the machine has entered `$state`: state written → enter hooks → transition `after` hooks.
     * A failure is wrapped in AfterHookFailedException.
     *
     * @param \Dudas\StateMachine\Contracts\Hook|callable(TransitionContext): mixed ...$hooks
     */
    public function onEnter(string $state, ...$hooks): self
    {
        foreach ($hooks as $hook) {
            $this->onEnter[$state][] = ClosureHook::wrap($hook);
        }

        return $this;
    }

    /**
     * Hooks run before the machine leaves `$state`, after the transition's `before` hooks; throwing aborts the transition.
     *
     * @param \Dudas\StateMachine\Contracts\Hook|callable(TransitionContext): mixed ...$hooks
     */
    public function onExit(string $state, ...$hooks): self
    {
        foreach ($hooks as $hook) {
            $this->onExit[$state][] = ClosureHook::wrap($hook);
        }

        return $this;
    }

    public function build(): Definition
    {
        if ($this->initial === null) {
            throw new InvalidDefinitionException('An initial state is required.');
        }

        return new Definition($this->initial, $this->states, $this->transitions, $this->onEnter, $this->onExit);
    }
}
