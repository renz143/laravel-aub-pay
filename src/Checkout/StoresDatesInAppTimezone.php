<?php

namespace Prycegas\AubPay\Checkout;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;

/**
 * Datetime columns hold wall-clock time with no zone attached, and Eloquent writes a Carbon in
 * whatever zone the instance happens to be in. AUB reports its times in GMT+8, so an expiry or a
 * payment time taken straight off one of its replies would be stored eight hours out on a UTC app
 * and read back as the wrong moment — a lapsed QR code shown as payable for another eight hours.
 * Converted to the application's zone on the way in, whatever the caller hands over.
 */
trait StoresDatesInAppTimezone
{
    public function fromDateTime($value)
    {
        if ($value instanceof DateTimeInterface) {
            $value = Date::instance($value)->setTimezone(date_default_timezone_get());
        }

        return parent::fromDateTime($value);
    }
}
