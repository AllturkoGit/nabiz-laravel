<?php

namespace Allturko\Nabiz\Transport;

use Throwable;

/**
 * Hub'a HMAC imzalı POST.
 *
 * Davranış garantisi: **hiçbir koşulda istisna fırlatmaz.** Hub erişilemezse,
 * yapılandırma eksikse veya ağ koparsa sessizce vazgeçilir — izlenen
 * uygulamada hata, log kirliliği veya yavaşlama oluşmaz.
 *
 * Ham curl kullanılır: bağımlılık politikası gereği ek paket eklenmez ve
 * zaman aşımı üzerinde tam denetim gerekir.
 */
class HubClient
{
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $key,
        private readonly ?string $secret,
        private readonly int $timeout,
    ) {}

    public function configured(): bool
    {
        return ! empty($this->url) && ! empty($this->key) && ! empty($this->secret);
    }

    /**
     * Sonuç döndürür ama **asla istisna fırlatmaz.**
     *
     * Sonucu yalnızca teşhis komutu okuyor; izleme yolu görmezden geliyor.
     * Gönderim başarısızsa yapılacak bir şey yok, izlenen uygulamayı bundan
     * haberdar etmek log kirliliğinden başka işe yaramaz (davranış garantisi 4).
     *
     * Yine de sonucu üretmek zorunlu: `nabiz:durum --test` "gönderildi" derken
     * gerçekte hiçbir şey gitmemiş olabiliyordu ve kuran kişi kurulumu çalışır
     * sanıyordu. Teşhis aracının yanlış teşhis koyması, hiç teşhis koymamaktan
     * kötüdür.
     *
     * @param  array<string, mixed>  $payload
     * @return array{sent: bool, status?: int, error?: string}
     */
    public function send(array $payload): array
    {
        if (! $this->configured()) {
            return ['sent' => false, 'error' => 'yapilandirma-eksik'];
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($body === false) {
                return ['sent' => false, 'error' => 'govde-kodlanamadi'];
            }

            $timestamp = (string) time();

            // Zaman damgası imzaya dahildir; olmasaydı saldırgan damgayı
            // değiştirip eski bir gövdeyi yeniden oynatabilirdi.
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->secret);

            $ch = curl_init(rtrim($this->url, '/').'/api/i/'.$this->key.'/server');

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT => $this->timeout,
                // Yönlendirme takip edilmez: imzalı gövdeyi bilinmeyen bir
                // adrese göndermek istemeyiz.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Nabiz-Signature: sha256='.$signature,
                    'X-Nabiz-Timestamp: '.$timestamp,
                ],
            ]);

            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result === false || $error !== '') {
                return ['sent' => false, 'error' => $error !== '' ? $error : 'curl-basarisiz'];
            }

            /*
            | Hub başarıda da geçersiz istekte de 204 döner (saldırgana geri
            | bildirim verilmez). Yani 204 "kabul edildi" demek DEĞİL, yalnızca
            | "istek ulaştı" demek. Teşhis komutu bunu açıkça yazıyor.
            */
            return ['sent' => $status > 0 && $status < 400, 'status' => $status];
        } catch (Throwable $e) {
            // Sessizce vazgeç. İzleme paketinin izlediği uygulamayı bozması,
            // çözdüğü sorundan büyük bir sorundur.
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }
}
