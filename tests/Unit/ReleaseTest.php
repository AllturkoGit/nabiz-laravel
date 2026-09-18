<?php

use Allturko\Nabiz\Support\Release;

/*
| Sürüm etiketi: NABIZ_RELEASE → CI/PaaS değişkeni → `.git` → yok.
| Kural Node ve Python paketleriyle aynı.
*/

const NABIZ_SHA = 'ABCDEF0123456789abcdef0123456789abcdef01';

function nabizTempDir(): string
{
    $dir = sys_get_temp_dir().'/nabiz-release-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function nabizWrite(string $path, string $content): void
{
    is_dir(dirname($path)) || mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}

function nabizRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    ) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

function nabizClearEnv(): void
{
    foreach (Release::ENV_VARS as $name) {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);
    }
}

beforeEach(function () {
    nabizClearEnv();
    $this->dir = nabizTempDir();
});

afterEach(function () {
    nabizClearEnv();
    nabizRemove($this->dir);
});

test('dal referansı okunur', function () {
    nabizWrite("{$this->dir}/.git/HEAD", "ref: refs/heads/main\n");
    nabizWrite("{$this->dir}/.git/refs/heads/main", NABIZ_SHA."\n");

    expect(Release::detect($this->dir))->toBe(['value' => 'abcdef012345', 'source' => '.git']);
});

test('gevşek referans yoksa packed-refs okunur', function () {
    nabizWrite("{$this->dir}/.git/HEAD", "ref: refs/heads/main\n");
    nabizWrite("{$this->dir}/.git/packed-refs", implode("\n", [
        '# pack-refs with: peeled fully-peeled sorted',
        str_repeat('1', 40).' refs/heads/eski',
        NABIZ_SHA.' refs/heads/main',
        '^'.str_repeat('2', 40),
        '',
    ]));

    expect(Release::detect($this->dir))->toBe(['value' => 'abcdef012345', 'source' => '.git']);
});

test('ayrık HEAD doğrudan commit', function () {
    nabizWrite("{$this->dir}/.git/HEAD", NABIZ_SHA."\n");

    expect(Release::detect($this->dir)['value'])->toBe('abcdef012345');
});

test('.git dosyası gitdir yolunu gösterir (göreli)', function () {
    $gercek = dirname($this->dir).'/'.basename($this->dir).'-gercek';
    nabizWrite("{$this->dir}/.git", 'gitdir: ../'.basename($gercek)."\n");
    nabizWrite("{$gercek}/HEAD", "ref: refs/heads/main\n");
    nabizWrite("{$gercek}/refs/heads/main", NABIZ_SHA);

    try {
        expect(Release::detect($this->dir)['value'])->toBe('abcdef012345');
    } finally {
        nabizRemove($gercek);
    }
});

test('.git dosyası mutlak gitdir yolunu gösterir', function () {
    nabizWrite("{$this->dir}/modules/app/HEAD", NABIZ_SHA);
    nabizWrite("{$this->dir}/proje/.git", "gitdir: {$this->dir}/modules/app\n");

    expect(Release::detect("{$this->dir}/proje")['value'])->toBe('abcdef012345');
});

test('bozuk ya da eksik .git hiçbir şey döndürmez, fırlatmaz', function (callable $hazirla) {
    $hazirla($this->dir);

    expect(Release::detect($this->dir))->toBe(['value' => null, 'source' => 'yok']);
})->with([
    'git yok' => [fn ($d) => null],
    'çöp HEAD' => [fn ($d) => nabizWrite("{$d}/.git/HEAD", "merhaba dünya\n")],
    'kısa sha' => [fn ($d) => nabizWrite("{$d}/.git/HEAD", "abcdef0\n")],
    'dal yok, packed-refs yok' => [fn ($d) => nabizWrite("{$d}/.git/HEAD", "ref: refs/heads/main\n")],
    'dal dosyası çöp' => [function ($d) {
        nabizWrite("{$d}/.git/HEAD", "ref: refs/heads/main\n");
        nabizWrite("{$d}/.git/refs/heads/main", 'xyz');
    }],
    'depo dışına çıkan ref' => [function ($d) {
        nabizWrite("{$d}/.git/HEAD", "ref: refs/../../disari\n");
        // refs/ var: yol gerçekten çözülebilir, yalnızca `..` denetimi durdurur.
        mkdir("{$d}/.git/refs");
        nabizWrite("{$d}/disari", NABIZ_SHA);
    }],
    'gitdir çöp' => [fn ($d) => nabizWrite("{$d}/.git", 'bu bir dizin değil')],
    'gitdir olmayan yer' => [fn ($d) => nabizWrite("{$d}/.git", "gitdir: /olmayan/yer\n")],
    'HEAD boş' => [fn ($d) => nabizWrite("{$d}/.git/HEAD", '')],
]);

test('ortam değişkeni .git\'e üstün gelir, sha kısaltılır', function () {
    nabizWrite("{$this->dir}/.git/HEAD", str_repeat('9', 40));
    putenv('SOURCE_VERSION= '.NABIZ_SHA.' ');

    expect(Release::detect($this->dir))->toBe(['value' => 'abcdef012345', 'source' => 'SOURCE_VERSION']);
});

test('ortam değişkenlerinde sıra korunur, boş olan atlanır', function () {
    putenv('GIT_COMMIT=  ');
    $_SERVER['COMMIT_SHA'] = 'ikinci';
    $_ENV['CI_COMMIT_SHA'] = 'sonuncu';

    expect(Release::detect($this->dir))->toBe(['value' => 'ikinci', 'source' => 'COMMIT_SHA']);
});

test('sha olmayan değer olduğu gibi, 64 karaktere kadar', function () {
    $_ENV['HEROKU_SLUG_COMMIT'] = ' v2.4.1-'.str_repeat('x', 100);

    $sonuc = Release::detect($this->dir);

    expect($sonuc['source'])->toBe('HEROKU_SLUG_COMMIT')
        ->and($sonuc['value'])->toBe('v2.4.1-'.str_repeat('x', 57));
});

test('7 haneli sha küçük harfe çevrilir', function () {
    putenv('GIT_SHA=ABCDEF1');

    expect(Release::detect($this->dir)['value'])->toBe('abcdef1');
});

test('NABIZ_RELEASE her şeye üstün gelir, olduğu gibi', function () {
    nabizWrite("{$this->dir}/.git/HEAD", NABIZ_SHA);
    putenv('GIT_COMMIT='.NABIZ_SHA);

    expect(Release::detect($this->dir, '  ABCDEF0123456789ABCDEF  '))
        ->toBe(['value' => 'ABCDEF0123456789ABCDEF', 'source' => 'NABIZ_RELEASE'])
        ->and(Release::detect($this->dir, str_repeat('r', 80))['value'])->toBe(str_repeat('r', 64))
        ->and(Release::detect($this->dir, '   ')['source'])->toBe('GIT_COMMIT');
});
