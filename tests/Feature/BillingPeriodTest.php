<?php

namespace Tests\Feature;

use App\Enums\BillingPeriod;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BillingPeriodTest extends TestCase
{
    public function test_monthly_duration_is_correct(): void
    {
        $start = Carbon::parse('2026-01-15');

        $this->assertSame('2026-02-15', BillingPeriod::MONTHLY->addTo($start)->toDateString());
    }

    public function test_monthly_duration_does_not_overflow_into_the_next_month(): void
    {
        // 31 Jan + 1 month must land on the last day of February (28 in a
        // non-leap year), never overflow into March.
        $start = Carbon::parse('2026-01-31');

        $this->assertSame('2026-02-28', BillingPeriod::MONTHLY->addTo($start)->toDateString());
    }

    public function test_yearly_duration_is_correct(): void
    {
        $start = Carbon::parse('2026-03-10');

        $this->assertSame('2027-03-10', BillingPeriod::YEARLY->addTo($start)->toDateString());
    }

    public function test_yearly_duration_handles_a_leap_day_start(): void
    {
        // 29 Feb 2028 (a leap year) + 1 year must land on 28 Feb 2029
        // (not a leap year), not overflow into March.
        $start = Carbon::parse('2028-02-29');

        $this->assertSame('2029-02-28', BillingPeriod::YEARLY->addTo($start)->toDateString());
    }

    public function test_invalid_billing_periods_are_rejected_and_default_safely(): void
    {
        $this->assertSame(BillingPeriod::MONTHLY, BillingPeriod::fromValue('fortnightly'));
        $this->assertSame(BillingPeriod::MONTHLY, BillingPeriod::fromValue(null));
        $this->assertSame(['monthly', 'yearly'], BillingPeriod::values());
    }

    public function test_the_yearly_multiplier_is_twelve_times_monthly(): void
    {
        $this->assertSame(1, BillingPeriod::MONTHLY->priceMultiplier());
        $this->assertSame(12, BillingPeriod::YEARLY->priceMultiplier());
    }
}
