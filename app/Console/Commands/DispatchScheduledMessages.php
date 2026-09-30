<?php

namespace App\Console\Commands;

use App\Jobs\PushOutgoingSmsToDeviceJob;
use App\Models\Message;
use Illuminate\Console\Command;

class DispatchScheduledMessages extends Command
{
    protected $signature = 'sms:dispatch-scheduled';

    protected $description = 'Dispatch scheduled outgoing SMS that are due';

    public function handle(): int
    {
        Message::query()
            ->where('direction', 'outgoing')
            ->where('status', 'queued')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->each(function (Message $message) {
                PushOutgoingSmsToDeviceJob::dispatch($message);
            });

        return self::SUCCESS;
    }
}
