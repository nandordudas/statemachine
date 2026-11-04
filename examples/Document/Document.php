<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Document;

/**
 * The domain object. It holds the state as an enum, so an invalid status can never be stored on it.
 */
final class Document
{
    public ?string $reviewer = null;

    public function __construct(
        public DocumentState $status = DocumentState::Draft,
    ) {}
}
