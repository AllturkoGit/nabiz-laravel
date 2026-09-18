<?php

use Allturko\Nabiz\Http\Middleware\MeasureRequest;
use Allturko\Nabiz\NabizServiceProvider;
use Allturko\Nabiz\Recorder;
use Allturko\Nabiz\Transport\HubClient;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\InteractsWithQueue;
use Symfony\Component\HttpFoundation\Response;

/*
| HTTP dışı yollar: kuyruk, zamanlayıcı, uzun ömürlü işçi.
|
| `job_failed` README'de yazıyordu ama hiç gönderilmiyordu; zamanlanmış
| görevin sessiz başarısızlığı hiç görünmüyordu; yalnızca kuyruk çalıştıran
| uygulama nabız atmıyordu.
*/

class NabizBozukIs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public function handle(): void
    {
        throw new RuntimeException('fatura servisi yanıt vermedi');
    }
}

beforeEach(function () {
    $this->gonderilenler = [];
    $liste = &$this->gonderilenler;

    $this->app->instance(HubClient::class, new class($liste) extends HubClient
    {
        public function __construct(public array &$liste)
        {
            parent::__construct('https://x.test', 'test-proje', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->liste[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    });
    $this->app->forgetInstance(Recorder::class);
});

afterEach(function () {
    unset($_SERVER['LARAVEL_OCTANE']);
});

test('başarısız kuyruk işi job_failed olarak tek kayıt açar', function () {
    config(['queue.default' => 'sync']);

    try {
        NabizBozukIs::dispatch();
    } catch (RuntimeException $e) {
        // Laravel aynı hatayı ardından handler'a da veriyor (Worker::runJob).
        $this->app->make(ExceptionHandler::class)->report($e);
    }

    $olaylar = array_values(array_filter($this->gonderilenler, fn ($o) => ! isset($o['events'])));

    expect(array_column($olaylar, 'kind'))->toBe(['job_failed'])
        ->and($olaylar[0]['route'])->toBe(NabizBozukIs::class)
        ->and($olaylar[0]['msg'])->toContain('fatura servisi');
});

test('başarısız zamanlanmış görev command_failed olarak gelir, PHP yolu atılır', function () {
    $gorev = $this->app->make(Schedule::class)->command('rapor:gonder --gunluk');
    $hata = new Exception("Scheduled command [{$gorev->command}] failed with exit code [1].");

    event(new ScheduledTaskFailed($gorev, $hata));
    $this->app->make(ExceptionHandler::class)->report($hata);

    $olaylar = array_values(array_filter($this->gonderilenler, fn ($o) => ! isset($o['events'])));

    expect(array_column($olaylar, 'kind'))->toBe(['command_failed'])
        ->and($olaylar[0]['route'])->toBe('artisan rapor:gonder --gunluk')
        // Mesajdaki PHP yolu da atılır; yoksa parmak izi sunucuya göre bölünür.
        ->and($olaylar[0]['msg'])->toBe('Scheduled command [artisan rapor:gonder --gunluk] failed with exit code [1].')
        ->and($olaylar[0]['msg'])->not->toContain(PHP_BINARY);
});

// Uzun sınıf adı jeton sanılıyordu: `App\Jobs\[jeton]`, farklı işler tek satır.
test('iş sınıfı adı maskelenmez', function () {
    $this->app->make(Recorder::class)->recordJobFailed(
        'App\Jobs\SendMonthlyInvoiceReminderEmails',
        new RuntimeException('smtp kapalı'),
    );

    expect($this->gonderilenler[0]['route'])->toBe('App\Jobs\SendMonthlyInvoiceReminderEmails');
});

test('kuyruk döngüsü canlılık gönderir', function () {
    event(new Looping('sync', 'default'));

    expect(array_filter($this->gonderilenler, fn ($o) => isset($o['events'])))->toHaveCount(1);
});

/*
| Octane'da Recorder yüzlerce istek yaşıyor. Başlangıç `??=` ile ilk isteğe
| yapışıyordu: ilk istekten sonra her istek "yavaş" görünürdü.
*/
test('istek bitince durum sıfırlanır, sonraki istek temiz başlar', function () {
    $recorder = $this->app->make(Recorder::class);
    $istek = Request::create('/rapor');

    $recorder->startRequest(microtime(true) - 5);
    $recorder->recordRequest($istek, new Response('ok'));

    $recorder->startRequest(microtime(true));
    $recorder->recordRequest($istek, new Response('ok'));

    $yavaslar = array_filter($this->gonderilenler, fn ($o) => ($o['kind'] ?? null) === 'slow_request');

    expect($yavaslar)->toHaveCount(1);
});

test('Octane işçisi tanınır', function () {
    expect(Recorder::octane())->toBeFalse();

    $_SERVER['LARAVEL_OCTANE'] = 1;

    expect(Recorder::octane())->toBeTrue();
});

/*
| Octane işçileri CLI altında çalışıyor. Sağlayıcı yalnızca
| runningInConsole()'a bakınca ölçüm hiç kurulmuyordu.
*/
test('Octane altında istek ölçümü kurulur', function () {
    $kernel = $this->app->make(Kernel::class);
    $provider = new NabizServiceProvider($this->app);

    $provider->boot();
    expect($kernel->hasMiddleware(MeasureRequest::class))->toBeFalse();

    $_SERVER['LARAVEL_OCTANE'] = 1;
    $provider->boot();

    expect($kernel->hasMiddleware(MeasureRequest::class))->toBeTrue();
});

/*
| Octane her isteği uygulamanın bir kopyasında işliyor. Recorder açılışta
| çözülmezse her kopya kendi Recorder'ını açıyor, kancalar ana
| uygulamadakine yazıyordu: istek kayıtlarında sorgu sayısı hep 0.
*/
test('Recorder açılışta çözülür, Octane kopyaları aynı örneği paylaşır', function () {
    $this->app->forgetInstance(Recorder::class);

    (new NabizServiceProvider($this->app))->boot();

    $kopya = clone $this->app;

    expect($kopya->make(Recorder::class))->toBe($this->app->make(Recorder::class));
});
