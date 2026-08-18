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
    protected $signature = 'nabiz:durum
                            {--test : Hub\'a sınama olayı gönderir — panelde hata olarak görünür}
                            {--nabiz : Yalnızca canlılık isteği gönderir — panele hata düşürmez}';

    protected $description = 'Nabız kurulumunu denetler';

    /** Hub'ın ürettiği secret bu uzunlukta; farklıysa eksik kopyalanmıştır. */
    private const SECRET_LENGTH = 64;

    /**
     * HubClient'a bilerek sorulmuyor: o singleton yapılandırmayı boot anında
     * dondurup saklıyor. Teşhis komutunun **güncel** yapılandırmayı raporlaması
     * gerekir, boot'ta ne varsa onu değil.
     */
    public function handle(): int
    {
        $secret = (string) config('nabiz.secret');

        $this->newLine();
        /*
        | Sürüm en üstte: "güncelleme geçti mi" sorusunun cevabı bu ve
        | onlarca kurulumda en sık sorulan şey o. Yapılandırma doğru olsa
        | bile eski sürüm eski davranışı sürdürür.
        */
        $this->row('Paket sürümü', Recorder::version());
        $this->row('Etkin', config('nabiz.enabled') ? 'evet' : 'HAYIR (NABIZ_ENABLED=false)');
        $this->row('Hub adresi', (string) config('nabiz.url') ?: 'TANIMSIZ');
        $this->row('Proje anahtarı', (string) config('nabiz.key') ?: 'TANIMSIZ');
        $this->row('Secret uzunluğu', $secret === '' ? 'TANIMSIZ' : strlen($secret).' karakter');
        $this->row('Ortam', (string) (config('nabiz.env') ?: app()->environment()));
        $this->newLine();

        $problems = $this->problems($secret);

        foreach ($problems as $problem) {
            $this->error('  ✗ '.$problem);
        }

        if ($problems !== []) {
            $this->newLine();

            return self::FAILURE;
        }

        $this->info('  ✓ Yapılandırma tamam.');

        if ($this->option('test') && ! $this->sendProbe()) {
            return self::FAILURE;
        }

        if ($this->option('nabiz') && ! $this->sendHeartbeat()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Verinin gerçekten ulaştığı yalnızca hub panelinden doğrulanır:');
        $this->line('  proje satırında bağlantı durumu "Bağlı" görünmelidir.');
        $this->line('  Hub geçersiz imzaya da 204 döner; buradan anlaşılmaz.');
        $this->newLine();

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function problems(string $secret): array
    {
        $problems = [];

        if (! config('nabiz.enabled')) {
            $problems[] = 'NABIZ_ENABLED=false — hiçbir kanca kurulmaz, hiçbir veri gönderilmez.';
        }

        $missing = collect(['NABIZ_URL' => 'url', 'NABIZ_KEY' => 'key', 'NABIZ_SECRET' => 'secret'])
            ->filter(fn (string $key) => empty(config("nabiz.{$key}")))
            ->keys();

        if ($missing->isNotEmpty()) {
            $problems[] = $missing->implode(', ').' tanımlı değil.';
        }

        if ($secret !== '' && strlen($secret) !== self::SECRET_LENGTH) {
            // En sık hata bu: secret kopyalanırken başı veya sonu eksik
            // kalıyor ve sonuç sessizce hiçbir şey göndermemek oluyor.
            $problems[] = sprintf(
                'NABIZ_SECRET %d karakter olmalı, %d karakter. Eksik kopyalanmış olabilir.',
                self::SECRET_LENGTH,
                strlen($secret),
            );
        }

        if (str_starts_with((string) config('nabiz.url'), 'http://')) {
            $problems[] = 'NABIZ_URL http:// ile başlıyor — secret imzası şifresiz hat üzerinden gider.';
        }

        return $problems;
    }

    /**
     * Sonuç okunuyor, "gönderdim" varsayılmıyor.
     *
     * Önceden koşulsuz başarı yazılıyordu: ağ koptuysa, zaman aşımı olduysa ya
     * da hub reddettiyse komut yine "✓ gönderildi" diyordu ve kuran kişi
     * kurulumu çalışır sanıyordu. Gerçek bir kurulumda tam olarak bu yaşandı.
     */
    /**
     * Canlılık isteği — bağlantıyı panele hata düşürmeden sınar.
     *
     * `--test` gerçek bir istisna gönderiyor ve bu tek kurulumu doğrularken
     * doğru: taşıma ve exception kancası birlikte sınanmış oluyor. Ama
     * onlarca kurulumu tek tek gezen bir döngüde aynı şey panele onlarca
     * sahte hata bırakır — izleme aracının kendi gürültüsünü üretmesi.
     *
     * Nabız bunu kirletmeden yapıyor: olay taşımıyor ama kabul edildiğinde
     * projenin bağlantı durumunu tazeliyor.
     */
    private function sendHeartbeat(): bool
    {
        $result = app(Recorder::class)->heartbeat();

        if ($result['sent'] ?? false) {
            $this->info(sprintf('  ✓ Canlılık isteği gönderildi (HTTP %s).', $result['status'] ?? '?'));

            return true;
        }

        $this->error(sprintf(
            '  ✗ Canlılık isteği GÖNDERİLEMEDİ: %s',
            $result['error'] ?? 'HTTP '.($result['status'] ?? 'bilinmiyor'),
        ));
        $this->newLine();

        return false;
    }

    private function sendProbe(): bool
    {
        // Gerçek bir istisna raporlanır: hem taşıma hem de exception kancası
        // aynı anda sınanmış olur.
        $result = app(Recorder::class)->recordException(
            new RuntimeException('nabiz:durum --test ile üretilen sınama olayı')
        );

        if ($result['sent'] ?? false) {
            $this->info(sprintf('  ✓ Sınama olayı gönderildi (HTTP %s).', $result['status'] ?? '?'));

            return true;
        }

        $this->error(sprintf(
            '  ✗ Sınama olayı GÖNDERİLEMEDİ: %s',
            $result['error'] ?? 'HTTP '.($result['status'] ?? 'bilinmiyor'),
        ));
        $this->newLine();

        return false;
    }

    private function row(string $label, string $value): void
    {
        $this->line(sprintf('  %-18s %s', $label, $value));
    }
}
