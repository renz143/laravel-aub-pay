<?php

namespace Prycegas\AubPay\Enums;

/**
 * `transactionResult` on an inquiry or notification.
 *
 * Three values where most gateways have two, and the third is the one that causes bugs: PENDING
 * means AUB does not yet know, not that the payment failed. Treating it as a failure releases
 * stock or cancels an order that may still be paid moments later.
 */
enum TransactionResult: string
{
    case Pending = 'PENDING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';

    public function isPaid(): bool
    {
        return $this === self::Success;
    }

    /**
     * True when the outcome is settled either way and will not change on its own.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
