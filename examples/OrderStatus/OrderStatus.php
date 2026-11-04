<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\OrderStatus;

/**
 * The enabled rows of the legacy system's `order_status` table (tests/databaseSeeds/order_status.sql).
 * Disabled rows (special, unpaid, out-of-stock, ...) have no group and are not modelled.
 * `New` is not a row: it stands for an order that has no status yet (`status_id` null).
 */
enum OrderStatus: string
{
    case New = 'new';

    case Processing = 'processing';
    case OnHold = 'on-hold';
    case Pending = 'pending';
    case Skip = 'skip';
    case WrongAddress = 'wrong-address';
    case ExchangeNeeds = 'exchange-needs';
    case Problematic = 'problematic';
    case ShipperPending = 'shipper-pending';

    case MustCall = 'must-call';
    case Sendable = 'sendable';
    case NoAnswer = 'no-answer';
    case InvalidPhone = 'invalid-phone';
    case Printed = 'printed';
    case Later = 'later';
    case ToPrint = 'to-print';
    case ExchangeReady = 'exchange-ready';
    case Unconfirmed = 'unconfirmed';
    case FailedWaybill = 'failed-waybill';
    case ShopPackable = 'shop-packable';
    case ShopReceivable = 'shop-receivable';
    case Servable = 'servable';
    case MissingShipper = 'missing-shipper';

    case Completed = 'completed';

    case Forgone = 'forgone';
    case Deleted = 'deleted';
    case Cancelled = 'cancelled';
    case Trash = 'trash';
    case Failed = 'failed';

    case SentBack = 'sent-back';
    case Returned = 'returned';

    public function group(): StatusGroup
    {
        return match ($this) {
            self::New => StatusGroup::None,
            self::Processing,
            self::OnHold,
            self::Pending,
            self::Skip,
            self::WrongAddress,
            self::ExchangeNeeds,
            self::Problematic,
            self::ShipperPending,
                => StatusGroup::Incoming,
            self::MustCall,
            self::Sendable,
            self::NoAnswer,
            self::InvalidPhone,
            self::Printed,
            self::Later,
            self::ToPrint,
            self::ExchangeReady,
            self::Unconfirmed,
            self::FailedWaybill,
            self::ShopPackable,
            self::ShopReceivable,
            self::Servable,
            self::MissingShipper,
                => StatusGroup::Serving,
            self::Completed => StatusGroup::Served,
            self::Forgone, self::Deleted, self::Cancelled, self::Trash, self::Failed => StatusGroup::Inactive,
            self::SentBack => StatusGroup::SentBack,
            self::Returned => StatusGroup::Returned,
        };
    }

    /**
     * @return list<self>
     */
    public static function inGroup(StatusGroup $group): array
    {
        return array_values(array_filter(self::cases(), static fn(self $s): bool => $s->group() === $group));
    }
}
