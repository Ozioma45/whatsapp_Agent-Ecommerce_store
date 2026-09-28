<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Support\WhatsApp\OutgoingWhatsAppMessageService;
use Illuminate\Console\Command;

/**
 * A deliberately minimal, development/support test tool — not a messaging
 * inbox. Requires the operator to identify themselves with --as, and only
 * proceeds if that user owns the target business or is a platform admin,
 * so it can never be used to send through another business's integration.
 */
class WhatsAppSendTestMessageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:test-message
        {business : The business handle to send from}
        {to : Recipient WhatsApp number, e.g. 15551234567}
        {--as= : Email of the business owner or a platform admin authorizing this test}
        {--message= : Custom message text (defaults to a canned test message)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[TEST MESSAGE] Send a single outgoing WhatsApp text message using one business\'s connected integration';

    public function handle(OutgoingWhatsAppMessageService $service): int
    {
        $business = Business::where('handle', $this->argument('business'))->first();

        if (! $business) {
            $this->error("No business found with handle [{$this->argument('business')}].");

            return self::FAILURE;
        }

        $actingEmail = $this->option('as');

        if (! $actingEmail) {
            $this->error('You must identify yourself with --as=<email> (the business owner or a platform admin).');

            return self::FAILURE;
        }

        $actor = User::where('email', $actingEmail)->first();

        if (! $actor) {
            $this->error("No user found with email [{$actingEmail}].");

            return self::FAILURE;
        }

        if ($actor->id !== $business->owner_id && ! $actor->isAdmin()) {
            $this->error("{$actor->email} is not authorized to send a test message for [{$business->handle}].");

            return self::FAILURE;
        }

        $text = $this->option('message') ?: "This is a test message from {$business->name}, sent via its WhatsApp Business Platform integration test tool.";

        $this->info('[TEST MESSAGE] Sending as '.$business->handle.' to '.$this->argument('to').'...');

        $result = $service->send($business, $this->argument('to'), $text);

        if ($result->successful) {
            $this->info("Accepted by WhatsApp (not yet confirmed delivered). Message ID: {$result->messageId}");

            return self::SUCCESS;
        }

        $this->error("Failed [{$result->status}]: {$result->error}");

        return self::FAILURE;
    }
}
