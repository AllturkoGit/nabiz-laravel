<?php

namespace Allturko\Nabiz\Http\Middleware;

use Allturko\Nabiz\Recorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * İstek süresini ölçer ve eşiği aşanları raporlar.
 *
 * Ölçüm `handle`'da başlar, gönderim `terminate`'te yapılır: yanıt kullanıcıya
 * çoktan iletilmiştir, raporlama kimseyi bekletmez.
 */
class MeasureRequest
{
    private float $startedAt = 0.0;

    public function __construct(private readonly Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        // LARAVEL_START uygulamanın gerçek başlangıcıdır; middleware'e
        // gelene kadar geçen süre de ölçüme dahil olsun.
        $this->startedAt = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->recorder->recordRequest(
                $request,
                $response,
                (microtime(true) - $this->startedAt) * 1000,
            );
        } catch (Throwable) {
            // Paket kendi hatasıyla uygulamayı etkilemez.
        }
    }
}
