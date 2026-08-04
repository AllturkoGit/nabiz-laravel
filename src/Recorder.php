<?php

namespace Allturko\Nabiz;

use Allturko\Nabiz\Support\Scrubber;
use Allturko\Nabiz\Transport\HubClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * İstek boyunca ölçüm biriktirir ve olayları hub'a gönderir.
 *
 * Tek istek yaşam döngüsü boyunca tekil (singleton) çalışır: sorgu sayacı ve
 * en yavaş sorgu burada tutulur, istek bitiminde raporla birlikte gönderilir.
 */
class Recorder
{
    private int $queryCount = 0;

    private ?float $slowestQueryMs = null;

    private ?string $slowestQuerySql = null;

    /** Aynı istekte aynı exception iki kez raporlanmasın. */
    private array $reported = [];

    /**
     * İstek başlangıcı. Middleware'de DEĞİL burada tutuluyor: Laravel
     * terminate() için middleware'i konteynerdan yeniden çözüyor ve yeni
     * örnekte alan boş kalıyordu. Sonuç süre olarak microtime × 1000
     * (~1,7 trilyon ms) çıkıyor, her istek "yavaş" görünüyordu.
     *
     * Recorder istek boyunca singleton; doğru yer burası.
     */
    private ?float $startedAt = null;

    public function __construct(
        private readonly HubClient $client,
        private readonly array $config,
    ) {}

    /** Middleware'in handle aşamasında çağrılır. */
    public function startRequest(float $at): void
    {
        // İlk çağrı kazanır: alt istekler (Route::dispatch) başlangıcı ezmesin.
        $this->startedAt ??= $at;
    }

    /** DB::listen kancasından çağrılır. */
    public function recordQuery(string $sql, float $timeMs): void
    {
        $this->queryCount++;

        if ($this->slowestQueryMs === null || $timeMs > $this->slowestQueryMs) {
            $this->slowestQueryMs = $timeMs;
            $this->slowestQuerySql = $sql;
        }

        if ($timeMs >= $this->config['slow_query_ms']) {
            $this->send([
                'kind' => 'slow_query',
                // Normalize edilmiş SQL mesaj olarak kullanılır: aynı sorgu
                // farklı parametrelerle çalıştığında tek grupta toplanır.
                'msg' => Scrubber::sql($sql),
                'slowest_query_sql' => Scrubber::sql($sql),
                'slowest_query_ms' => (int) round($timeMs),
            ]);
        }
    }

    public function recordException(Throwable $e): void
    {
        if ($this->ignored($e) || $this->alreadyReported($e)) {
            return;
        }

        $this->send([
            'kind' => 'exception',
            // QueryException mesajı SQL'i bağlanmış değerlerle taşır; oturum
            // kimliği, e-posta, kart numarası oradan sızabilir.
            'msg' => Scrubber::message($e->getMessage(), $this->sqlIceriyor($e)),
            'exception_class' => $e::class,
            'stack' => Scrubber::stack($e->getTraceAsString()),
            'file' => Scrubber::path($e->getFile()),
            'line' => $e->getLine(),
        ]);
    }

    /**
     * İstek bitiminde çağrılır (terminate). Kullanıcı yanıtı çoktan
     * gönderilmiştir; burada harcanan süre kimseyi bekletmez.
     */
    public function recordRequest(Request $request, Response $response): void
    {
        if ($this->startedAt === null) {
            return;
        }

        $durationMs = (microtime(true) - $this->startedAt) * 1000;
        $status = $response->getStatusCode();
        $slow = $durationMs >= $this->config['slow_request_ms'];

        if (! $slow && $status < 500) {
            return;
        }

        $this->send([
            'kind' => $status >= 500 ? 'http_5xx' : 'slow_request',
            'msg' => $this->routePattern($request).' — '.($status >= 500
                ? "HTTP {$status}"
                : round($durationMs).' ms'),
            'route' => $this->routePattern($request),
            'method' => $request->getMethod(),
            'status' => $status,
            'controller' => $this->controller($request),
            'duration_ms' => (int) round($durationMs),
            'memory_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
        ]);
    }

    /**
     * Ortak alanları ekleyip gönderir.
     *
     * @param  array<string, mixed>  $event
     */
    private function send(array $event): void
    {
        try {
            $this->client->send(array_filter([
                ...$event,
                'env' => $this->config['env'],
                'release' => $this->config['release'],
                'query_count' => $this->queryCount,
                'slowest_query_ms' => $event['slowest_query_ms']
                    ?? ($this->slowestQueryMs !== null ? (int) round($this->slowestQueryMs) : null),
                'slowest_query_sql' => $event['slowest_query_sql']
                    ?? Scrubber::sql($this->slowestQuerySql),
                'php_version' => PHP_VERSION,
                'framework_version' => app()->version(),
                'user_id' => $this->userId(),
            ], fn ($v) => $v !== null));
        } catch (Throwable) {
            // Kendi hatasını raporlamaz — sonsuz döngü riski.
        }
    }

    /**
     * Route deseni gönderilir, gerçek id değil: `/api/products/42` yerine
     * `GET /api/products/{id}`. Böylece hem gruplama çalışır hem yolda
     * kişisel veri taşınmaz.
     */
    private function routePattern(Request $request): string
    {
        $uri = $request->route()?->uri();

        return $request->getMethod().' /'.ltrim($uri ?? Scrubber::path($request->getPathInfo()) ?? '', '/');
    }

    private function controller(Request $request): ?string
    {
        $action = $request->route()?->getActionName();

        return is_string($action) && $action !== 'Closure' ? $action : null;
    }

    /**
     * Varsayılan kapalı (capture_user_id). Açmak kimliği belirli bir kişiye
     * bağlamak demektir ve bilinçli bir karardır.
     */
    private function userId(): int|string|null
    {
        if (! $this->config['capture_user_id']) {
            return null;
        }

        try {
            return auth()->id();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Mesajı SQL taşıyan istisnalar. Sınıf adına bakılıyor çünkü paket
     * illuminate/database'e bağımlı değil ve olmamalı.
     */
    private function sqlIceriyor(Throwable $e): bool
    {
        return str_contains($e::class, 'QueryException')
            || str_contains($e::class, 'PDOException');
    }

    private function ignored(Throwable $e): bool
    {
        foreach ($this->config['ignore'] as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function alreadyReported(Throwable $e): bool
    {
        $id = spl_object_id($e);

        if (isset($this->reported[$id])) {
            return true;
        }

        $this->reported[$id] = true;

        return false;
    }
}
