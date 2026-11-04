<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Document;

enum DocumentState: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Archived = 'archived';
}
