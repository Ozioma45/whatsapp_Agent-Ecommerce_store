<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Notifications\SubscriptionExpired;
use App\Notifications\SubscriptionExpiringSoon;
use App\Support\SubscriptionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * The subscription lifecycle sweep: expires anything whose paid period
 * has actually run out, promotes any scheduled downgrade whose start
 * date has arrived, and sends "expiring soon" reminders — all without
 * needing a user to visit the subscription page. Intended to run daily
 * (see routes/console.php's Schedule::command()).
 *
 * Idempotent: every query here is scoped to a status a successful run
 * always moves rows OUT of (active → expired/cancelled, pending-scheduled
 * → active), or guarded by a "reminder already sent" timestamp — so
 * running this twice in a row (or any number of times) never double-acts
 * on the same row, and never touches a business or subscription it has
 * no business touching.
 */
class ExpireSubscriptionsCommand extends Command
{
    /**
     * How many days before expiry to send the "expiring soon" reminder.
     */
    private const REMINDER_WINDOW_DAYS = 3;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire subscriptions past their expiry date, promote scheduled downgrades, and send expiry reminders';

    public function handle(SubscriptionService $service): int
    {
        $expired = 0;
        $cancelled = 0;

        Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->toDateString())
            ->get()
            ->each(function (Subscription $subscription) use (&$expired, &$cancelled) {
                $newStatus = $subscription->hasRequestedCancellation()
                    ? Subscription::STATUS_CANCELLED
                    : Subscription::STATUS_EXPIRED;

                $subscription->update(['status' => $newStatus]);
                $newStatus === Subscription::STATUS_CANCELLED ? $cancelled++ : $expired++;

                $this->notify($subscription, new SubscriptionExpired($subscription));
            });

        $promoted = $service->promoteScheduledChanges();

        $reminded = $this->sendExpiryReminders();

        $this->info("Expired {$expired}, cancelled {$cancelled} subscription(s); promoted {$promoted} scheduled change(s); sent {$reminded} expiry reminder(s).");

        return self::SUCCESS;
    }

    /**
     * Sends one "expiring soon" reminder per subscription that's within
     * the reminder window and hasn't already received one — guarded by
     * expiry_reminder_sent_at, set right after a successful send, so a
     * daily (or more frequent) run never sends duplicates.
     */
    private function sendExpiryReminders(): int
    {
        $sent = 0;

        Subscription::where('status', Subscription::STATUS_ACTIVE)
            ->whereNull('expiry_reminder_sent_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->toDateString(), now()->addDays(self::REMINDER_WINDOW_DAYS)->toDateString()])
            ->get()
            ->each(function (Subscription $subscription) use (&$sent) {
                $this->notify($subscription, new SubscriptionExpiringSoon($subscription));
                $subscription->update(['expiry_reminder_sent_at' => now()]);
                $sent++;
            });

        return $sent;
    }

    /**
     * A notification failure must never interrupt the sweep — the
     * subscription's own status change has already been saved by the
     * time this is called.
     */
    private function notify(Subscription $subscription, object $notification): void
    {
        try {
            $subscription->business?->owner?->notify($notification);
        } catch (Throwable $e) {
            $this->warn("Could not send a notification for subscription #{$subscription->id}.");
        }
    }
}
