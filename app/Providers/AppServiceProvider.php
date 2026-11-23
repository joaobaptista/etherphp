<?php

namespace App\Providers;

use App\Contracts\GoogleOAuthServiceContract;
use App\Contracts\GooglePhotosPickerContract;
use App\Contracts\ImageProcessorContract;
use App\Contracts\JwtServiceContract;
use App\Contracts\PaymentGatewayContract;
use App\Contracts\RealtimeBroadcasterContract;
use App\Contracts\VaultCryptoServiceContract;
use App\Contracts\VaultStorageServiceContract;
use App\Contracts\VideoAudioProcessorContract;
use App\Models\Capsule;
use App\Models\Guardian;
use App\Models\Memory;
use App\Models\Message;
use App\Models\User;
use App\Observers\CapsuleObserver;
use App\Observers\MemoryObserver;
use App\Policies\CapsulePolicy;
use App\Policies\GuardianPolicy;
use App\Policies\MemoryPolicy;
use App\Policies\MessagePolicy;
use App\Services\FfmpegVideoAudioProcessorService;
use App\Services\GoogleOAuthService;
use App\Services\GooglePhotosPickerService;
use App\Services\InterventionImageProcessorService;
use App\Services\JwtService;
use App\Services\LocalVaultStorageService;
use App\Services\MercadoPagoPaymentGateway;
use App\Services\NullRealtimeBroadcaster;
use App\Services\VaultCryptoService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtServiceContract::class, JwtService::class);
        $this->app->singleton(VaultCryptoServiceContract::class, VaultCryptoService::class);
        $this->app->singleton(VaultStorageServiceContract::class, LocalVaultStorageService::class);
        $this->app->singleton(ImageProcessorContract::class, InterventionImageProcessorService::class);
        $this->app->singleton(VideoAudioProcessorContract::class, FfmpegVideoAudioProcessorService::class);
        $this->app->singleton(GoogleOAuthServiceContract::class, GoogleOAuthService::class);
        $this->app->singleton(GooglePhotosPickerContract::class, GooglePhotosPickerService::class);
        $this->app->singleton(PaymentGatewayContract::class, MercadoPagoPaymentGateway::class);
        $this->app->singleton(RealtimeBroadcasterContract::class, NullRealtimeBroadcaster::class);
    }

    public function boot(): void
    {
        Capsule::observe(CapsuleObserver::class);
        Memory::observe(MemoryObserver::class);

        Gate::policy(Capsule::class, CapsulePolicy::class);
        Gate::policy(Memory::class, MemoryPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);
        Gate::policy(Guardian::class, GuardianPolicy::class);

        // Scramble API docs: open in the local environment, elsewhere only when explicitly enabled
        Gate::define('viewApiDocs', static fn (?User $user = null): bool => (bool) config('ethernity.security.api_docs_public'));

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            if (! (bool) config('ethernity.security.rate_limit_enabled', false)) {
                return [Limit::none()];
            }

            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinutes(15, 30)->by('login-ip:'.$request->ip()),
                Limit::perMinutes(15, 10)->by('login-email:'.($email !== '' ? $email : (string) $request->ip())),
            ];
        });

        RateLimiter::for('register', function (Request $request): Limit {
            if (! (bool) config('ethernity.security.rate_limit_enabled', false)) {
                return Limit::none();
            }

            return Limit::perHour(10)->by('register-ip:'.$request->ip());
        });

        RateLimiter::for('vault-proof', function (Request $request): Limit {
            $user = $request->user();
            $userId = $user !== null ? (string) $user->id : (string) $request->ip();

            return Limit::perMinutes(15, 10)->by('vault-proof:'.$userId);
        });
    }
}
