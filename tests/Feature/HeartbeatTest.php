<?php

use Allturko\Nabiz\Recorder;
use Allturko\Nabiz\Transport\HubClient;
use Illuminate\Support\Facades\Cache;

/**
 * Hub bir kurulumun çalıştığını yalnızca hata gelmesinden anlıyordu; hatasız
 * uygulama "kurulum bozuk" görünüyordu. Kanıt artık isteğin kendisi.
 */
function nabizKaydedici(array &$gonderilenler): Recorder
{
    $client = new class($gonderilenler) extends HubClient
    {
        public function __construct(public array &$gonderilenler)
        {
            parent::__construct('https://x.test', 'ornek-proje', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            $this->gonderilenler[] = $payload;

            return ['sent' => true, 'status' => 204];
        }
    };

    return new Recorder($client, [
        'key' => 'ornek-proje',
        'env' => 'testing', 'release' => null,
        'slow_request_ms' => 1000, 'slow_query_ms' => 500,
        'capture_user_id' => false, 'ignore' => [],
    ]);
}

beforeEach(fn () => Cache::flush());

test('canlılık isteği olay taşımaz', function () {
    $gonderilenler = [];

    $sonuc = nabizKaydedici($gonderilenler)->heartbeat();

    expect($sonuc['sent'])->toBeTrue()
        ->and($gonderilenler)->toHaveCount(1)
        // Boş toplu istek: hub sıfır olay işler ama canlılığı damgalar.
        ->and($gonderilenler[0]['events'])->toBe([])
        // Hangi eksenin canlı olduğu env ve kaynaktan okunuyor.
        ->and($gonderilenler[0]['env'])->toBe('testing');
});

/**
 * Nabız isteğe bağlı: her istekte gerçek bir HTTP çağrısı yapmak izlenen
 * uygulamayı yavaşlatırdı. Aradaki çağrılar yalnızca önbellek okur.
 */
test('canlılık sekiz saatte bir gönderilir', function () {
    $gonderilenler = [];
    $recorder = nabizKaydedici($gonderilenler);

    $recorder->heartbeatIfDue();
    $recorder->heartbeatIfDue();
    $recorder->heartbeatIfDue();

    expect($gonderilenler)->toHaveCount(1);
});

test('pencere dolunca yeniden gönderilir', function () {
    $gonderilenler = [];
    $recorder = nabizKaydedici($gonderilenler);

    $recorder->heartbeatIfDue();
    Cache::flush();
    $recorder->heartbeatIfDue();

    expect($gonderilenler)->toHaveCount(2);
});

/**
 * Önbellek anahtarı proje bazlı: aynı cache deposunu paylaşan iki uygulama
 * birbirinin nabzını bastırmamalı.
 */
test('farklı projeler birbirinin nabzını bastırmaz', function () {
    $a = [];
    $b = [];

    $birinci = nabizKaydedici($a);
    $ikinci = new Recorder(
        new class extends HubClient
        {
            public array $gonderilenler = [];

            public function __construct()
            {
                parent::__construct('https://x.test', 'ikinci-proje', str_repeat('s', 64), 1);
            }

            public function send(array $payload): array
            {
                $this->gonderilenler[] = $payload;

                return ['sent' => true, 'status' => 204];
            }
        },
        ['key' => 'ikinci-proje', 'env' => 'testing', 'release' => null,
            'slow_request_ms' => 1000, 'slow_query_ms' => 500,
            'capture_user_id' => false, 'ignore' => []],
    );

    $birinci->heartbeatIfDue();
    $ikinci->heartbeatIfDue();

    expect($a)->toHaveCount(1)
        ->and(Cache::has('nabiz:heartbeat:ornek-proje'))->toBeTrue()
        ->and(Cache::has('nabiz:heartbeat:ikinci-proje'))->toBeTrue();
});
