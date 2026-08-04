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
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        if (! $this->configured()) {
            return;
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($body === false) {
                return;
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

            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable) {
            // Sessizce vazgeç. İzleme paketinin izlediği uygulamayı bozması,
            // çözdüğü sorundan büyük bir sorundur.
        }
    }
}
