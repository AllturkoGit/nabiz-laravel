<?php

use Allturko\Nabiz\Recorder;
use Allturko\Nabiz\Transport\HubClient;

/**
 * Teşhis komutunun tek işi var: yerelde yanlış olanı görünür kılmak.
 * Sessiz kalması, yanlış rapor vermesinden daha kötüdür.
 */
test('eksik yapılandırma bildirilir', function () {
    config()->set('nabiz.url', null);
    config()->set('nabiz.key', null);
    config()->set('nabiz.secret', null);

    $this->artisan('nabiz:durum')->assertFailed();
});

test('kısa secret yakalanır', function () {
    config()->set('nabiz.url', 'https://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 32));

    $this->artisan('nabiz:durum')
        ->expectsOutputToContain('64 karakter olmalı')
        ->assertFailed();
});

test('şifresiz hub adresi uyarı üretir', function () {
    config()->set('nabiz.url', 'http://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 64));

    $this->artisan('nabiz:durum')->assertFailed();
});

test('doğru yapılandırma başarılı döner', function () {
    config()->set('nabiz.enabled', true);
    config()->set('nabiz.url', 'https://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 64));

    $this->artisan('nabiz:durum')->assertSuccessful();
});

test('kapalıyken durum bunu açıkça söyler', function () {
    config()->set('nabiz.enabled', false);
    config()->set('nabiz.url', 'https://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 64));

    $this->artisan('nabiz:durum')
        ->expectsOutputToContain('NABIZ_ENABLED=false')
        ->assertFailed();
});

/**
 * Teşhis komutu gönderim sonucunu okumalı, "gönderdim" varsaymamalı.
 *
 * Gerçek bir kurulumda koşulsuz "✓ gönderildi" yazdı, olay hub'a hiç ulaşmadı
 * ve durum ancak saatler sonra veritabanına bakılınca anlaşıldı.
 */
test('gönderim başarısızsa test komutu başarısız döner', function () {
    config()->set('nabiz.enabled', true);
    config()->set('nabiz.url', 'https://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 64));

    // Hub'a ulaşılamıyor: gerçek istek yerine başarısız sonuç döndüren istemci.
    app()->instance(HubClient::class, new class extends HubClient
    {
        public function __construct()
        {
            parent::__construct('https://hub.ornek', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            return ['sent' => false, 'error' => 'Could not resolve host'];
        }
    });

    $this->artisan('nabiz:durum --test')
        ->expectsOutputToContain('GÖNDERİLEMEDİ')
        ->assertFailed();
});

test('gönderim başarılıysa durum kodu yazılır', function () {
    config()->set('nabiz.enabled', true);
    config()->set('nabiz.url', 'https://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 64));

    app()->instance(HubClient::class, new class extends HubClient
    {
        public function __construct()
        {
            parent::__construct('https://hub.ornek', 'k', str_repeat('s', 64), 1);
        }

        public function send(array $payload): array
        {
            return ['sent' => true, 'status' => 204];
        }
    });

    $this->artisan('nabiz:durum --test')
        ->expectsOutputToContain('HTTP 204')
        ->assertSuccessful();
});

/**
 * "Güncelleme geçti mi" sorusunun cevabı bu satır. Yapılandırma doğru olsa
 * bile eski sürüm eski davranışı sürdürür ve onlarca kurulumda tek tek
 * sunucuya girmeden bunu bilmenin başka yolu yok.
 */
test('kurulu paket sürümü yazdırılır', function () {
    config()->set('nabiz.url', 'https://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 64));

    $this->artisan('nabiz:durum')
        ->expectsOutputToContain('Paket sürümü')
        ->assertSuccessful();
});

/**
 * Onlarca kurulumu gezen bir döngüde `--test` panele onlarca sahte hata
 * bırakırdı — izleme aracının kendi gürültüsünü üretmesi. Nabız aynı şeyi
 * kirletmeden kanıtlıyor.
 */
test('nabiz seçeneği hata değil canlılık gönderir', function () {
    config()->set('nabiz.url', 'https://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 64));

    $gonderilenler = [];

    app()->instance(Recorder::class, new Recorder(
        new class($gonderilenler) extends HubClient
        {
            public function __construct(public array &$gonderilenler)
            {
                parent::__construct('https://x.test', 'k', str_repeat('s', 64), 1);
            }

            public function send(array $payload): array
            {
                $this->gonderilenler[] = $payload;

                return ['sent' => true, 'status' => 204];
            }
        },
        ['key' => 'k', 'env' => 'testing', 'release' => null,
            'slow_request_ms' => 1000, 'slow_query_ms' => 500,
            'capture_user_id' => false, 'ignore' => []],
    ));

    $this->artisan('nabiz:durum --nabiz')->assertSuccessful();

    expect($gonderilenler)->toHaveCount(1)
        // Olay taşımaz: panelde hata kaydı oluşmaz.
        ->and($gonderilenler[0]['events'])->toBe([])
        ->and($gonderilenler[0])->not->toHaveKey('kind');
});
