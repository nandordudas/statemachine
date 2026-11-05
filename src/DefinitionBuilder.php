<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * Fluent assembly of a {@see Definition}:
 *
 *     $definition = Definition::builder()
 *         ->initial(S::Stopped)
 *         ->states(...S::cases())
 *         ->transition(Transition::on(E::Play, [S::Stopped, S::Paused], S::Playing))
 *         ->build();
 */
final class DefinitionBuilder
{
    private string|BackedEnum|null $initial = null;

    /** @var list<string|BackedEnum> */
    private array $states = [];

    /** @var list<Transition> */
    private array $transitions = [];

    /** @var array<string, list<Hook>> */
    private array $onEnter = [];

    /** @var array<string, list<Hook>> */
    private array $onExit = [];

    /** @var array<string, array<string, mixed>> */
    private array $stateMeta = [];

    public function initial(string|BackedEnum $state): self
    {
        $this->initial = $state;

        return $this;
    }

    public function states(string|BackedEnum ...$states): self
    {
        array_push($this->states, ...$states);

        return $this;
    }

    public function transition(Transition ...$transitions): self
    {
        array_push($this->transitions, ...$transitions);

        return $this;
    }

    /**
     * Hooks run once the machine has entered `$state`: state written → enter hooks → transition `after` hooks.
     * A failure is wrapped in AfterHookFailedException.
     *
     * @param Hook|callable(TransitionContext): mixed ...$hooks
     */
    public function onEnter(string|BackedEnum $state, Hook|callable ...$hooks): self
    {
        foreach ($hooks as $hook) {
            $this->onEnter[Name::of($state)][] = ClosureHook::wrap($hook);
        }

        return $this;
    }

    /**
     * Hooks run before the machine leaves `$state`, after the transition's `before` hooks; throwing aborts the transition.
     *
     * @param Hook|callable(TransitionContext): mixed ...$hooks
     */
    public function onExit(string|BackedEnum $state, Hook|callable ...$hooks): self
    {
        foreach ($hooks as $hook) {
            $this->onExit[Name::of($state)][] = ClosureHook::wrap($hook);
        }

        return $this;
    }

    /**
     * Metadata for `$state`, merged with what was given before: `->meta(S::Review, ['label' => 'In review'])`.
     *
     * @param array<string, mixed> $meta
     */
    public function meta(string|BackedEnum $state, array $meta): self
    {
        $name = Name::of($state);
        $this->stateMeta[$name] = [...($this->stateMeta[$name] ?? []), ...$meta];

        return $this;
    }

    public function build(): Definition
    {
        return new Definition(
            $this->initial ?? throw new InvalidDefinitionException('An initial state is required.'),
            $this->states,
            $this->transitions,
            $this->onEnter,
            $this->onExit,
            $this->stateMeta,
        );
    }
}
