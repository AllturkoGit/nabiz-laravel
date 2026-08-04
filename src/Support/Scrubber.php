<?php

namespace Allturko\Nabiz\Support;

/**
 * Kişisel veri temizliği — gönderimden ÖNCE uygulanır.
 *
 * Hub tarafında aynı temizlik tekrar yapılır. İkisi de gereklidir: hub kendini
 * savunur, paket ise kişisel veriyi ağa hiç çıkarmaz. Bir sızıntı olacaksa
 * ilk savunma hattı burasıdır.
 */
class Scrubber
{
    /**
     * Sıra önemlidir: IBAN ve kart numarası rakam dizileridir, telefon
     * deseninden önce denenmezse o desen onları parçalar.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u' => '[eposta]',
        '/\bTR(?:[\s-]?\d){24}\b/i' => '[iban]',
        '/\b\d(?:[\s-]?\d){12,18}\b/' => '[kart]',
        '/\b[1-9]\d{10}\b/' => '[tckn]',
        '/(?:\+90|0)?[\s-]?5\d{2}[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}\b/' => '[telefon]',
    ];

    public static function text(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        foreach (self::PATTERNS as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return mb_substr($value, 0, $limit);
    }

    /** Query string ve fragment atılır; yalnızca yol kalır. */
    public static function path(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH) ?: strtok($value, '?#');

        if (! is_string($path) || $path === '') {
            return null;
        }

        return self::text($path, 300);
    }

    /**
     * SQL normalize: literal değerler `?` ile değiştirilir.
     * `where email = 'ahmet@ornek.com'` → `where email = ?`
     *
     * Bu hem KVKK gereği hem gruplama açısından doğrudur: aynı sorgu farklı
     * parametrelerle çalıştığında tek parmak izinde toplanır.
     */
    public static function sql(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = preg_replace("/'(?:[^']|'')*'/", '?', $value) ?? $value;
        $value = preg_replace('/"(?:[^"]|"")*"/', '?', $value) ?? $value;
        $value = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $value) ?? $value;
        $value = preg_replace('/\b(IN)\s*\(\s*\?(?:\s*,\s*\?)+\s*\)/i', '$1 (?)', $value) ?? $value;

        return self::text(preg_replace('/\s+/', ' ', trim($value)), 500);
    }

    /**
     * Stack trace kısaltılır ve maskelenir. Vendor satırları atılmaz —
     * hatanın nerede olduğunu bulmak için zincirin tamamı gerekir — ama
     * uzunluk sınırlanır.
     */
    public static function stack(?string $value): ?string
    {
        return self::text($value, 2000);
    }
}
