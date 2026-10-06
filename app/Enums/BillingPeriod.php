<?php

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * The only two billing periods a subscription may be recorded with. The
 * `subscriptions.billing_period` column stays a plain string (no DB enum
 * type, no app-wide cast change) so existing rows and the admin's own
 * free-typed historical values keep working — this enum is instead the
 * single place every part of the app validates against and computes
 * durations from, so "monthly"/"yearly" can never drift into an arbitrary
 * free-form value anywhere new code is added.
 */
enum BillingPeriod: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::MONTHLY;
    }

    /**
     * The date one full period after $date — using calendar-correct
     * arithmetic (addMonthsNoOverflow/addYearsNoOverflow), not a fixed
     * day count, so e.g. 31 Jan + 1 month lands on 28/29 Feb rather than
     * overflowing into March, and 29 Feb + 1 year lands on 28 Feb.
     */
    public function addTo(Carbon $date): Carbon
    {
        return match ($this) {
            self::MONTHLY => $date->copy()->addMonthsNoOverflow(1),
            self::YEARLY => $date->copy()->addYearsNoOverflow(1),
        };
    }

    /**
     * The multiplier applied to a plan's single (monthly) database price
     * to charge for this period — yearly is 12× the current monthly
     * price, computed server-side at payment time. There is no separate
     * yearly price column; this is a documented, fixed multiplier, never
     * a value accepted from the browser.
     */
    public function priceMultiplier(): int
    {
        return match ($this) {
            self::MONTHLY => 1,
            self::YEARLY => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MONTHLY => 'Monthly',
            self::YEARLY => 'Yearly',
        };
    }
}
