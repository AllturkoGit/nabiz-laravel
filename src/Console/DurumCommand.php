<?php

namespace Allturko\Nabiz\Console;

use Allturko\Nabiz\Recorder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Kurulum teşhisi.
 *
 * Bu komut, paketin en pahalı arıza biçimi için var: **sessiz çalışmama.**
 * Yapılandırma eksikken ya da secret yanlışken hiçbir şey patlamaz, hiçbir
 * log düşmez — hub geçersiz isteğe de 204 döner (saldırgana geri bildirim
 * verilmez). Sonuç, kimsenin fark etmediği bir izleme kurulumu.
 *
 * Komut tek başına "çalışıyor" diyemez: hub geçerli ile geçersiz imzayı
 * dışarıya aynı yanıtla karşılar. Yaptığı iş, yerel tarafta yanlış olan ne
 * varsa göstermek ve doğrulamanın hub panelinden yapılacağını söylemek.
 */
class DurumCommand extends Command
{
    protected $signature = 'nabiz:durum {--test : Hub\'a bir sınama olayı gönderir}';

    protected $description = 'Nabız kurulumunu denetler';

    /** Hub'ın ürettiği secret bu uzunlukta; farklıysa eksik kopyalanmıştır. */
    private const SECRET_UZUNLUGU = 64;

    /**
     * HubClient'a bilerek sorulmuyor: o singleton yapılandırmayı boot anında
     * dondurup saklıyor. Teşhis komutunun **güncel** yapılandırmayı raporlaması
     * gerekir, boot'ta ne varsa onu değil.
     */
    public function handle(): int
    {
        $secret = (string) config('nabiz.secret');

        $this->newLine();
        $this->satir('Etkin', config('nabiz.enabled') ? 'evet' : 'HAYIR (NABIZ_ENABLED=false)');
        $this->satir('Hub adresi', (string) config('nabiz.url') ?: 'TANIMSIZ');
        $this->satir('Proje anahtarı', (string) config('nabiz.key') ?: 'TANIMSIZ');
        $this->satir('Secret uzunluğu', $secret === '' ? 'TANIMSIZ' : strlen($secret).' karakter');
        $this->satir('Ortam', (string) (config('nabiz.env') ?: app()->environment()));
        $this->newLine();

        $sorunlar = $this->sorunlar($secret);

        foreach ($sorunlar as $sorun) {
            $this->error('  ✗ '.$sorun);
        }

        if ($sorunlar !== []) {
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  ✓ Yapılandırma tamam.');

        if ($this->option('test')) {
            $this->sinamaGonder();
        }

        $this->newLine();
        $this->line('  Verinin gerçekten ulaştığı yalnızca hub panelinden doğrulanır:');
        $this->line('  proje satırında bağlantı durumu "Bağlı" görünmelidir.');
        $this->line('  Hub geçersiz imzaya da 204 döner; buradan anlaşılmaz.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function sorunlar(string $secret): array
    {
        $sorunlar = [];

        if (! config('nabiz.enabled')) {
            $sorunlar[] = 'NABIZ_ENABLED=false — hiçbir kanca kurulmaz, hiçbir veri gönderilmez.';
        }

        $eksik = collect(['NABIZ_URL' => 'url', 'NABIZ_KEY' => 'key', 'NABIZ_SECRET' => 'secret'])
            ->filter(fn (string $anahtar) => empty(config("nabiz.{$anahtar}")))
            ->keys();

        if ($eksik->isNotEmpty()) {
            $sorunlar[] = $eksik->implode(', ').' tanımlı değil.';
        }

        if ($secret !== '' && strlen($secret) !== self::SECRET_UZUNLUGU) {
            // En sık hata bu: secret kopyalanırken başı veya sonu eksik
            // kalıyor ve sonuç sessizce hiçbir şey göndermemek oluyor.
            $sorunlar[] = sprintf(
                'NABIZ_SECRET %d karakter olmalı, %d karakter. Eksik kopyalanmış olabilir.',
                self::SECRET_UZUNLUGU,
                strlen($secret),
            );
        }

        if (str_starts_with((string) config('nabiz.url'), 'http://')) {
            $sorunlar[] = 'NABIZ_URL http:// ile başlıyor — secret imzası şifresiz hat üzerinden gider.';
        }

        return $sorunlar;
    }

    private function sinamaGonder(): void
    {
        // Gerçek bir istisna raporlanır: hem taşıma hem de exception kancası
        // aynı anda sınanmış olur.
        app(Recorder::class)->recordException(
            new RuntimeException('nabiz:durum --test ile üretilen sınama olayı')
        );

        $this->info('  ✓ Sınama olayı gönderildi.');
    }

    private function satir(string $etiket, string $deger): void
    {
        $this->line(sprintf('  %-18s %s', $etiket, $deger));
    }
}
