<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Examples;

/**
 * Oracle: a line-for-line port of LogAn's current behaviour, i.e.
 * StatusObserverService::validateGroupChange() followed by the local pickup checks of
 * OrderModel::validateStatusTransition(), working on raw status keys and group strings.
 * Deliberately shares no code with the machine under test.
 */
final class LegacyOrderStatusRule
{
    private const array MOVEMENTS = [
        ['', 'active/incoming'],
        ['', 'inactive'],
        ['', 'active/serving'],
        ['active/incoming', 'active/serving'],
        ['active/serving', 'active/incoming'],
        ['active/incoming', 'inactive'],
        ['inactive', 'active/incoming'],
        ['active/serving', 'inactive'],
        ['active/serving', 'served'],
    ];

    /**
     * @param string $old status key, '' for a new order
     * @param array<string, string> $groups status key => group
     */
    public static function allows(string $old, string $new, bool $localPickup, array $groups): bool
    {
        $oldGroup = $old === '' ? '' : $groups[$old];
        $newGroup = $groups[$new];

        $groupOk = false;

        if ($old === 'completed') {
            $groupOk = in_array($new, ['forgone', 'returned'], true);
        } elseif ($old === 'sent-back' && in_array($new, ['returned'], true)) {
            $groupOk = true;
        } elseif ($oldGroup === $newGroup) {
            $groupOk = true;
        } else {
            foreach (self::MOVEMENTS as [$from, $to]) {
                if ($from === $oldGroup && $to === $newGroup) {
                    $groupOk = true;
                }
            }
        }

        if (!$groupOk) {
            return false;
        }

        if ($localPickup) {
            if (in_array($new, ['sendable', 'servable'], true) && $old === 'shop-packable') {
                return false;
            }

            return !($new === 'completed' && $old !== 'shop-receivable');
        }

        return !in_array($new, ['shop-packable', 'shop-receivable'], true);
    }
}
