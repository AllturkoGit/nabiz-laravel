<?php

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
