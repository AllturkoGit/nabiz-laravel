<?php

namespace Allturko\Nabiz;

use Allturko\Nabiz\Console\DurumCommand;
use Allturko\Nabiz\Http\Middleware\MeasureRequest;
use Allturko\Nabiz\Transport\HubClient;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
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
                // Canlılık önbelleği proje bazlı anahtarlanıyor; aynı cache
                // deposunu paylaşan iki uygulama birbirinin nabzını
                // bastırmasın.
                'key' => config('nabiz.key'),
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

        if ($this->app->runningInConsole()) {
            $this->commands([DurumCommand::class]);
        }

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
     * Exception kancası — iki yoldan.
     *
     * **1. MessageLogged olayı (birincil).** Laravel'in handler'ı raporlanan
     * her istisnayı `['exception' => $e]` bağlamıyla loglar. Bu olay handler
     * sınıfından bağımsızdır ve her zaman tetiklenir.
     *
     * **2. reportable (ikincil).** Standart kurulumlarda çalışır.
     *
     * Neden ikisi birden: Collision gibi paketler konsolda handler'ı kendi
     * sarmalayıcısıyla değiştiriyor ve boot sırasında kaydedilen reportable
     * geri çağrısı başka bir örnekte kalabiliyor — gerçek bir kurulumda tam
     * olarak bu yaşandı, hiçbir istisna raporlanmadı. Çift kayıt sorun değil:
     * Recorder aynı istisnayı iki kez göndermez (spl_object_id ile eler).
     *
     * `reportable` **`false` DÖNDÜRMEZ** — döndürseydi Laravel'in kendi
     * loglaması durur ve paket uygulamanın davranışını değiştirmiş olurdu.
     */
    private function hookExceptions(): void
    {
        try {
            $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event) {
                $e = $event->context['exception'] ?? null;

                if ($e instanceof Throwable) {
                    $this->record($e);
                }
            });
        } catch (Throwable) {
            // Sessiz.
        }

        // Sağlayıcı sırası önemli: tüm paketler yüklendikten sonra çözülür.
        $this->app->booted(function () {
            try {
                $handler = $this->app->make(ExceptionHandler::class);

                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(fn (Throwable $e) => $this->record($e));
                }
            } catch (Throwable) {
                // Sessiz.
            }
        });
    }

    private function record(Throwable $e): void
    {
        try {
            $this->app->make(Recorder::class)->recordException($e);
        } catch (Throwable) {
            // Kendi hatasını raporlamaz — sonsuz döngü riski.
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
