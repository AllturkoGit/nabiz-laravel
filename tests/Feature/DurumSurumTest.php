<?php

use Allturko\Nabiz\Recorder;
use Allturko\Nabiz\Support\Packagist;
use Allturko\Nabiz\Support\Release;

/*
| `nabiz:durum`: sürüm etiketi ve Packagist güncelleme denetimi.
|
| Güncelleme denetimi yalnızca bilgi verir; eski sürüm yapılandırma hatası
| değil, çıkış kodu değişmez.
*/

function nabizPackagist(?string $body, string $installed = '0.2.2'): Packagist
{
    return new class($body, $installed) extends Packagist
    {
        public int $calls = 0;

        public function __construct(private ?string $body, private string $version) {}

        public function installed(): string
        {
            return $this->version;
        }

        protected function fetch(): ?string
        {
            $this->calls++;

            return $this->body;
        }
    };
}

function nabizPackagistBody(array $versions): string
{
    return json_encode(['packages' => ['allturko/nabiz' => array_map(
        fn ($v) => ['name' => 'allturko/nabiz', 'version' => $v],
        $versions,
    )]]);
}

beforeEach(function () {
    config()->set('nabiz.url', 'https://hub.ornek');
    config()->set('nabiz.key', 'ornek-proje');
    config()->set('nabiz.secret', str_repeat('a', 64));
    config()->set('nabiz.env', 'production');
});

afterEach(function () {
    putenv('NABIZ_DURUM_CEVRIMDISI');
    putenv('GIT_COMMIT');
});

test('en yeni kararlı sürüm seçilir: sayısal karşılaştırma, v öneki, dev ve ön sürüm atlanır', function () {
    expect(Packagist::pickLatest(json_decode(nabizPackagistBody([
        'dev-main', 'v0.9.9', 'v0.10.0', '1.0.0-beta1', 'v0.10.1-RC1', '0.2.3', 'v1.0.x-dev',
    ]), true)))->toBe('0.10.0')
        ->and(Packagist::pickLatest([]))->toBeNull()
        ->and(Packagist::pickLatest(['packages' => ['allturko/nabiz' => [['version' => 'dev-main']]]]))->toBeNull();
});

test('daha yeni sürüm varsa uyarı yazılır, komut yine başarılı', function () {
    app()->instance(Packagist::class, nabizPackagist(nabizPackagistBody(['v0.2.2', 'v0.10.1', 'v0.9.0']), 'v0.2.2'));

    $this->artisan('nabiz:durum')
        ->expectsOutputToContain('Güncel sürüm       0.10.1')
        ->expectsOutputToContain('Güncelleme var: composer require allturko/nabiz:^0.10')
        ->assertSuccessful();
});

test('kurulu sürüm güncelse uyarı yok', function (string $installed) {
    app()->instance(Packagist::class, nabizPackagist(nabizPackagistBody(['v0.2.2', 'v0.2.3']), $installed));

    $this->artisan('nabiz:durum')
        ->expectsOutputToContain('Güncel sürüm       0.2.3')
        ->doesntExpectOutputToContain('Güncelleme var')
        ->assertSuccessful();
})->with([['v0.2.3'], ['0.2.3'], ['0.3.0'], ['bilinmiyor'], ['dev-main']]);

test('ağ hatasında denetlenemedi yazılır, komut başarısız sayılmaz', function () {
    app()->instance(Packagist::class, nabizPackagist(null));

    $this->artisan('nabiz:durum')
        ->expectsOutputToContain('Güncel sürüm       denetlenemedi')
        ->assertSuccessful();
});

test('bozuk yanıt denetlenemedi sayılır', function () {
    app()->instance(Packagist::class, nabizPackagist('<html>'));

    $this->artisan('nabiz:durum')->expectsOutputToContain('denetlenemedi')->assertSuccessful();
});

test('NABIZ_DURUM_CEVRIMDISI=1 denetimi atlar', function () {
    $sahte = nabizPackagist(nabizPackagistBody(['v9.0.0']));
    app()->instance(Packagist::class, $sahte);
    putenv('NABIZ_DURUM_CEVRIMDISI=1');

    $this->artisan('nabiz:durum')
        ->doesntExpectOutputToContain('Güncel sürüm')
        ->assertSuccessful();

    expect($sahte->calls)->toBe(0);
});

test('sürüm etiketi ve kaynağı gösterilir', function () {
    config()->set('nabiz.release', 'v2.4.1');

    $this->artisan('nabiz:durum')
        ->expectsOutputToContain('Sürüm etiketi      v2.4.1 (kaynak: NABIZ_RELEASE)')
        ->assertSuccessful();

    config()->set('nabiz.release', null);
    putenv('GIT_COMMIT='.str_repeat('ab', 20));

    $this->artisan('nabiz:durum')
        ->expectsOutputToContain('Sürüm etiketi      abababababab (kaynak: GIT_COMMIT)')
        ->assertSuccessful();
});

test('Recorder sürüm etiketini otomatik bulur, yapılandırma üstün gelir', function () {
    $release = fn () => (fn () => $this->config['release'])->call(app(Recorder::class));

    config()->set('nabiz.release', null);
    putenv('GIT_COMMIT='.str_repeat('cd', 20));
    app()->forgetInstance(Recorder::class);

    expect($release())->toBe('cdcdcdcdcdcd');

    config()->set('nabiz.release', 'elle-yazilan');
    app()->forgetInstance(Recorder::class);

    expect($release())->toBe('elle-yazilan')
        ->and(Release::detect(base_path(), 'elle-yazilan')['source'])->toBe('NABIZ_RELEASE');
});
