<?php

use App\Actions\Subscriptions\ExpireSubscriptionsAction;
use App\Enums\PickerSessionStatusEnum;
use App\Models\GooglePhotosPickerSession;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    GooglePhotosPickerSession::query()
        ->whereIn('status', [PickerSessionStatusEnum::Pending->value, PickerSessionStatusEnum::Picking->value])
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->update(['status' => PickerSessionStatusEnum::Expired->value]);
})->hourly()->name('ethernity:expire-picker-sessions');

// Processes queued jobs (e.g. Google Photos imports) on hosts without a resident worker:
// the same cron that runs schedule:run drains the queue every minute. On a VPS, a
// supervisor-managed `php artisan queue:work` can be used instead (this then finds it empty).
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1')
    ->everyMinute()
    ->name('ethernity:drain-queue')
    ->withoutOverlapping(30)
    ->runInBackground();

// Ends plans whose paid period is over and closes unpaid PIX charges.
Schedule::call(fn () => app(ExpireSubscriptionsAction::class)->execute())
    ->hourly()
    ->name('ethernity:expire-subscriptions')
    ->withoutOverlapping();
