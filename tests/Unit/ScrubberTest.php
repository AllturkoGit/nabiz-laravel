<?php

use Allturko\Nabiz\Support\Scrubber;

test('kişisel veri desenleri maskelenir', function (string $girdi, string $beklenen) {
    expect(Scrubber::text($girdi, 500))->toBe($beklenen);
})->with([
    'e-posta' => ['Kullanıcı ahmet@ornek.com bulunamadı', 'Kullanıcı [eposta] bulunamadı'],
    'TC kimlik' => ['TCKN 12345678901 geçersiz', 'TCKN [tckn] geçersiz'],
    'IBAN' => ['TR330006100519786457841326 reddedildi', '[iban] reddedildi'],
    'kart' => ['4111 1111 1111 1111 hatalı', '[kart] hatalı'],
    'telefon' => ['Ara: 0555 123 45 67', 'Ara: [telefon]'],
]);

test('query string atılır, yol kalır', function () {
    expect(Scrubber::path('/urunler?email=x@y.com&token=abc'))->toBe('/urunler')
        ->and(Scrubber::path('https://erkpa.com.tr/urunler?q=1'))->toBe('/urunler');
});

test('SQL literal değerleri normalize edilir', function () {
    expect(Scrubber::sql("select * from users where email = 'ahmet@ornek.com'"))
        ->toBe('select * from users where email = ?');

    expect(Scrubber::sql('select * from orders where user_id = 42'))
        ->toBe('select * from orders where user_id = ?');

    expect(Scrubber::sql('select * from t where id in (1, 2, 3)'))
        ->toBe('select * from t where id in (?)');
});

test('normalize edilmiş SQL kişisel veri içermez', function () {
    $sonuc = Scrubber::sql("insert into users (email, tc) values ('a@b.com', '12345678901')");

    expect($sonuc)->not->toContain('a@b.com')
        ->and($sonuc)->not->toContain('12345678901');
});

test('stack trace 2000 karakterde kesilir', function () {
    expect(Scrubber::stack(str_repeat('x', 5000)))->toHaveLength(2000);
});

test('boş değerler null döner', function () {
    expect(Scrubber::text(null, 500))->toBeNull()
        ->and(Scrubber::path(''))->toBeNull()
        ->and(Scrubber::sql(null))->toBeNull();
});
