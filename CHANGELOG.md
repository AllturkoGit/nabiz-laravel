# Değişiklik günlüğü

Semver. Hub'ın toplama ucu sözleşmesi geriye uyumlu tutulur: yeni alanlar
eklenebilir, mevcut alanlar kaldırılmaz. Eski paket sürümleri çalışmaya
devam eder.

```bash
composer require allturko/nabiz
```

---

## 0.2.0

**Hub gereksinimi — bu sürümde önemli.** Ölümcül hatalar `fatal` türüyle
gönderiliyor ve hub'ın izinli tür listesinde bu tür bulunmalı. Hub
güncellenmeden yükseltilirse **ölümcül hatalar sessizce düşer**: toplama ucu
geçersiz olaya da 204 döner, hiçbir yerde iz kalmaz. Önce hub deploy edilmeli.

Canlılık nabzı için de canlılık kolonlarının göçü gerekir; o eksikse nabız
zarar vermez, yalnızca kaydedilmez.

### Eklendi

- **Ölümcül hata yakalama.** Bellek tükenmesi, zaman aşımı ve derleme
  hataları PHP'de exception üretmez: süreç ölür, exception handler hiç
  çalışmaz. Siteyi **gerçekten düşüren** hata sınıfı bugüne kadar panele hiç
  düşmedi — uptime probu 500'ü görüyordu ama sebebini kimse bilmiyordu.

  `register_shutdown_function` ile yakalanıyor. `exception` değil `fatal`
  türüyle gönderiliyor: biri uygulamanın yakalayabildiği bir durum, diğeri
  sunucunun sınırına çarpması, ve bu sınıfı ayrıca süzebilmek gerekiyor.

  Açılışta 256 KB tampon ayrılıp shutdown anında serbest bırakılıyor. Bellek
  tükendiğinde raporlama kodunun kendisi de yer bulamaz; tampon olmadan kanca
  yazılır ama tam ihtiyaç anında sessizce çalışmaz.

  Yalnızca ölümcül türler gönderilir (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`,
  `E_COMPILE_ERROR`, `E_USER_ERROR`). Uyarı ve bildirimler her istekte
  onlarca üretilebilir ve paneli boğardı.

- **Canlılık nabzı.** Sekiz saatte bir olay taşımayan bir istek gönderilir.
  Hub bir kurulumun çalıştığını yalnızca gelen hatalardan anlıyordu; hatasız
  çalışan uygulama "kurulum bozuk" görünüyordu.

  Laravel zamanlayıcısına **bağlanmadı**: paket onlarca projeye kuruluyor ve
  hepsinde çalışan bir cron olduğu varsayılamaz. `terminate()` aşamasında,
  yanıt gönderildikten sonra atılır; süresi gelmemişse yalnızca bir önbellek
  okuması yapılır.

  Yan fayda: hub'ın kendi uptime probu da bir istektir, yani hiç ziyaretçisi
  olmayan site bile nabzını atmaya devam eder.

### Düzeltildi

- `Recorder`'ın yapılandırmasında proje anahtarı yoktu. Canlılık önbelleği
  bu anahtarla ayrıldığı için, aynı cache deposunu paylaşan iki uygulama
  birbirinin nabzını bastırırdı.

---

## 0.1.2

- Teşhis komutu gerçek gönderim sonucunu okuyor.

## 0.1.1

- Exception kancası kurulum sırasına bağlı olarak kaçırılabiliyordu.

## 0.1.0

- İlk sürüm.
