<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

/**
 * Renders a {@see Definition} as text diagrams. Guarded transitions are marked with `[guard]`.
 */
final class Diagram
{
    public static function mermaid(Definition $definition): string
    {
        $lines = ['stateDiagram-v2'];

        foreach ($definition->states as $state) {
            if (self::id($state) !== $state) {
                $lines[] = sprintf('    state "%s" as %s', $state, self::id($state));
            }
        }

        $lines[] = '    [*] --> ' . self::id($definition->initial);

        foreach ($definition->transitions as $transition) {
            foreach ($transition->from as $from) {
                $lines[] = sprintf('    %s --> %s : %s', self::id($from), self::id($transition->to), self::label($transition));
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public static function dot(Definition $definition): string
    {
        $lines = ['digraph machine {', '    rankdir=LR;', '    start [shape=point];'];

        foreach ($definition->states as $state) {
            $lines[] = sprintf('    "%s" [shape=%s];', addcslashes($state, '"\\'), $state === $definition->initial ? 'doublecircle' : 'circle');
        }

        $lines[] = sprintf('    start -> "%s";', addcslashes($definition->initial, '"\\'));

        foreach ($definition->transitions as $transition) {
            foreach ($transition->from as $from) {
                $lines[] = sprintf(
                    '    "%s" -> "%s" [label="%s"];',
                    addcslashes($from, '"\\'),
                    addcslashes($transition->to, '"\\'),
                    addcslashes(self::label($transition), '"\\'),
                );
            }
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    private static function label(Transition $transition): string
    {
        return $transition->event . ($transition->guards === [] ? '' : ' [guard]');
    }

    private static function id(string $state): string
    {
        return preg_replace('/\W+/', '_', $state) ?? $state;
    }

    private function __construct() {}
}
