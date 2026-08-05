# allturko/nabiz

Laravel uygulamaları için Nabız izleme istemcisi. Exception'ları, yavaş istekleri ve yavaş
SQL sorgularını **kişisel veri toplamadan** merkezî Nabız Hub'a raporlar.

> Bu paket izlenen projelere kurulur. Hub'ın kendisi ayrı bir repodadır:
> [nabiz-hub](https://github.com/AllturkoGit/nabiz-hub)

| | |
|---|---|
| **Paket** | `allturko/nabiz` |
| **Destek** | Laravel 11 · 12 · 13 · PHP 8.2+ |
| **Lisans** | MIT |
| **Durum** | 🚧 Geliştirme aşamasında (Faz 2) |

---

## Kurulum

```bash
composer require allturko/nabiz
php artisan vendor:publish --tag=nabiz
```

`.env` dosyasına:

```env
NABIZ_ENABLED=true
NABIZ_URL=https://monitor.allturko.erkpa.com.tr
NABIZ_KEY=erkpa-api
NABIZ_SECRET=...
NABIZ_ENV=production
```

Service provider Laravel'in paket keşfi ile otomatik yüklenir; elle kayıt gerekmez.
Anahtar ve secret değerleri Nabız panelindeki **Kurulum** sekmesinden alınır.

### Kurulumu doğrulayın

```bash
php artisan nabiz:durum
```

Bu adım atlanmamalı. Paketin en pahalı arıza biçimi sessiz çalışmamadır: secret eksik
kopyalanmışsa hiçbir şey patlamaz, hiçbir log düşmez — hub geçersiz imzaya da `204`
döner. Kurulum aylarca çalışmıyor olabilir ve bu sessizlik "sorun yok" sanılır.

Komut yerelde yanlış olan ne varsa gösterir: eksik değişken, hatalı secret uzunluğu,
şifresiz hub adresi.

```bash
php artisan nabiz:durum --test   # hub'a bir sınama olayı gönderir
```

Verinin gerçekten ulaştığı yalnızca **hub panelinden** doğrulanır: proje satırındaki
bağlantı durumu `Bağlı` görünmelidir. Komut bunu kendi başına söyleyemez, çünkü hub
geçerli ile geçersiz imzayı dışarıya aynı yanıtla karşılar.

---

## Ne toplar

| Alan | Örnek |
|---|---|
| `kind` | `exception` · `http_5xx` · `slow_request` · `slow_query` · `job_failed` |
| `msg`, `exception_class`, `stack`, `file`, `line` | Hata bilgisi (maskelemeden geçer) |
| `route` | `GET /api/products/{id}` — **desen, gerçek id değil** |
| `controller`, `method`, `status` | `ProductController@show`, `GET`, `500` |
| `duration_ms`, `memory_mb` | Performans |
| `query_count` | SQL sorgu sayısı — N+1 tespiti |
| `slowest_query_sql` | **Normalize:** `select * from users where email = ?` |
| `release`, `php_version`, `framework_version` | Ortam |

## Ne toplamaz

Bunlar tasarım kısıtıdır, yapılandırmayla açılamaz:

- ❌ IP adresi
- ❌ User-Agent
- ❌ İstek gövdesi (form verisi)
- ❌ Query string değerleri
- ❌ Oturum verisi, çerez
- ❌ `user_id` (varsayılan kapalı, açıkça etkinleştirilmedikçe)
- ❌ Ham SQL literal değerleri — sorgular normalize edilir

Kayıttan önce mesaj ve stack trace içindeki e-posta, TC kimlik no, telefon, IBAN ve kart
numarası desenleri maskelenir.

---

## Davranış garantileri

Paket, kurulduğu uygulamanın davranışını **değiştirmez**:

- Exception'ı yutmaz, `throw` zincirini kesmez
- Kendi hatasını raporlamaya çalışmaz (sonsuz döngü riski)
- Hub'a ulaşamazsa sessizce vazgeçer, uygulamada hata üretmez
- Raporlama isteği kullanıcının isteğini bekletmez
- `NABIZ_ENABLED=false` iken hiçbir kanca kurulmaz

---

## Yapılandırma

`config/nabiz.php` içinde:

| Ayar | Varsayılan | Açıklama |
|---|---|---|
| `slow_request_ms` | 1000 | Bu süreyi aşan istekler `slow_request` olarak raporlanır |
| `slow_query_ms` | 500 | Bu süreyi aşan sorgular `slow_query` olarak raporlanır |
| `capture_user_id` | `false` | Açılırsa kimliği belirli kişiye bağlı veri toplanır |
| `ignore` | `[]` | Raporlanmayacak exception sınıfları |
| `timeout` | 2 | Hub'a bağlanma zaman aşımı (sn) |

---

## Güvenlik

Sunucudan sunucuya gönderim HMAC ile imzalanır:

- `X-Nabiz-Signature: sha256=<hmac_sha256(gövde, NABIZ_SECRET)>`
- `X-Nabiz-Timestamp` — 5 dakikadan eski istekler hub tarafından reddedilir (replay koruması)
- İmza karşılaştırması `hash_equals()` ile yapılır

**`NABIZ_SECRET` yalnızca sunucuda bulunur.** Tarayıcıya veya istemci tarafına asla
konulmaz — tarayıcı hataları için ayrı, anahtar tabanlı `t.js` kullanılır.

---

## Sürümleme

Semver. Hub'ın toplama ucu sözleşmesi geriye uyumlu tutulur: yeni alanlar eklenebilir,
mevcut alanlar kaldırılmaz. Eski paket sürümleri çalışmaya devam eder.

---

## Geliştirme

```bash
composer install
./vendor/bin/pest
./vendor/bin/pint
```

Yayınlama: `git tag v1.x.x && git push --tags` → Packagist webhook ile otomatik güncellenir.
