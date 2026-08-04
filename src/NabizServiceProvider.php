<?php

namespace Allturko\Nabiz;

use Allturko\Nabiz\Http\Middleware\MeasureRequest;
use Allturko\Nabiz\Transport\HubClient;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Throwable;

class NabizServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nabiz.php', 'nabiz');

        $this->app->singleton(HubClient::class, fn ($app) => new HubClient(
            url: config('nabiz.url'),
            key: config('nabiz.key'),
            secret: config('nabiz.secret'),
            timeout: config('nabiz.timeout'),
        ));

        $this->app->singleton(Recorder::class, fn ($app) => new Recorder(
            client: $app->make(HubClient::class),
            config: [
                'env' => config('nabiz.env') ?: $app->environment(),
                'release' => config('nabiz.release'),
                'slow_request_ms' => config('nabiz.slow_request_ms'),
                'slow_query_ms' => config('nabiz.slow_query_ms'),
                'capture_user_id' => config('nabiz.capture_user_id'),
                'ignore' => config('nabiz.ignore', []),
            ],
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/nabiz.php' => config_path('nabiz.php'),
        ], 'nabiz');

        // NABIZ_ENABLED=false iken hiçbir kanca kurulmaz — sıfır ek yük.
        if (! config('nabiz.enabled')) {
            return;
        }

        // Yapılandırma eksikse de kanca kurulmaz: her istekte boşuna
        // ölçüm yapmanın anlamı yok.
        if (! $this->app->make(HubClient::class)->configured()) {
            return;
        }

        $this->hookQueries();
        $this->hookExceptions();
        $this->hookRequests();
    }

    /** Yavaş sorgu ve sorgu sayacı. */
    private function hookQueries(): void
    {
        try {
            DB::listen(function (QueryExecuted $query) {
                try {
                    $this->app->make(Recorder::class)->recordQuery($query->sql, $query->time);
                } catch (Throwable) {
                    // Sessiz.
                }
            });
        } catch (Throwable) {
            // Veritabanı yapılandırılmamış olabilir; izleme buna takılmaz.
        }
    }

    /**
     * Exception kancası.
     *
     * `reportable` yalnızca raporlama zincirine eklenir; **`false` DÖNDÜRÜLMEZ**
     * — döndürülseydi Laravel'in kendi loglaması durur ve paket uygulamanın
     * davranışını değiştirmiş olurdu. Exception yutulmaz, `throw` zinciri
     * kesilmez.
     */
    private function hookExceptions(): void
    {
        try {
            $handler = $this->app->make(ExceptionHandler::class);

            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $e) {
                try {
                    $this->app->make(Recorder::class)->recordException($e);
                } catch (Throwable) {
                    // Kendi hatasını raporlamaz — sonsuz döngü riski.
                }
            });
        } catch (Throwable) {
            // Sessiz.
        }
    }

    /** Yavaş istek ve 5xx ölçümü. */
    private function hookRequests(): void
    {
        try {
            if (! $this->app->runningInConsole()) {
                $this->app->make(Kernel::class)->pushMiddleware(MeasureRequest::class);
            }
        } catch (Throwable) {
            // Sessiz.
        }
    }
}
