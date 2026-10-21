<?php
/**
 * Vega ERP self-deploy endpoint (public_html/deploy.php). Standalone: does NOT boot Laravel to authenticate,
 * so it can recover a broken app. Layout: <home>/laravel (app), <home>/public_html (this file), <home>/deploy-work (state).
 *
 * Every request: POST, headers X-Vega-Timestamp (unix, +-300 s), X-Vega-Nonce (32-64 hex, single use),
 * X-Vega-Signature = hex HMAC-SHA256(secret, "VEGA-DEPLOY-HMAC-V1\n{ts}\n{nonce}\n{sorted query}\n{sha256hex(body)}").
 * Secret: laravel/.deploy-secret (created randomly if missing) or DEPLOY_SECRET in laravel/.env.
 * Package: Ed25519 signature over "VEGA-DEPLOY-PKG-V1\n{sha256hex(zip)}", public key DEPLOY_PUBLIC_KEY (.env) or config/deploy.php.
 * Actions (?action=): status | init (id,size,sha256,sig) | chunk (id,offset; raw body) | finalize (id) | rollback.
 * Anything unauthenticated gets a bare 404.
 */
declare(strict_types=1);
@ini_set('display_errors', '0');
error_reporting(E_ALL);

const VD_HOME_DEFAULT = __DIR__.'/..';
const VD_MAX_SKEW = 300;
const VD_DEFAULT_MAX_BYTES = 314572800; // 300 MB

$VD = [
    'home' => realpath(getenv('VEGA_DEPLOY_HOME') ?: VD_HOME_DEFAULT) ?: VD_HOME_DEFAULT,
    'log' => [],
    'busy' => false, // true while the swap is in progress (shutdown handler rolls back on fatal errors)
    'responded' => false,
];
$VD['app'] = $VD['home'].'/laravel';
$VD['prev'] = $VD['home'].'/laravel-prev';
$VD['failed'] = $VD['home'].'/laravel-failed';
$VD['work'] = $VD['home'].'/deploy-work';
$VD['pub'] = __DIR__;

function vd_404(): never
{
    global $VD;
    $VD['responded'] = true;
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><title>404 Not Found</title><h1>Not Found</h1>";
    exit;
}

function vd_json(array $data, int $code = 200): never
{
    global $VD;
    $VD['responded'] = true;
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data + ['log' => $VD['log']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function vd_log(string $m): void
{
    global $VD;
    $VD['log'][] = date('H:i:s').' '.$m;
}

function vd_env(string $key): ?string
{
    global $VD;
    $v = getenv($key);
    if (is_string($v) && $v !== '') {
        return $v;
    }
    $f = $VD['app'].'/.env';
    if (! is_file($f)) {
        $f = $VD['prev'].'/.env';
    }
    if (is_file($f) && preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents($f), $m)) {
        $v = trim($m[1]);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }

        return $v === '' ? null : $v;
    }

    return null;
}

function vd_bytes(string $v): int
{
    $v = trim($v);
    $n = (int) $v;

    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

function vd_mkdir(string $d): void
{
    if (! is_dir($d) && ! @mkdir($d, 0700, true) && ! is_dir($d)) {
        throw new RuntimeException("Cannot create {$d}");
    }
}

function vd_rmrf(string $p): void
{
    if (is_link($p) || is_file($p)) {
        @unlink($p);

        return;
    }
    if (! is_dir($p)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        ($f->isDir() && ! $f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($p);
}

/** JSON state file under deploy-work, read-modify-write under an exclusive lock. */
function vd_state(string $name, callable $fn): mixed
{
    global $VD;
    vd_mkdir($VD['work']);
    $h = fopen($VD['work'].'/'.$name.'.json', 'c+');
    flock($h, LOCK_EX);
    $data = json_decode((string) stream_get_contents($h), true) ?: [];
    $res = $fn($data);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($data));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);

    return $res;
}

function vd_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
}

/** Rate limit: per IP, 10 auth failures or 400 requests per 10 minutes. */
function vd_rate(bool $fail = false): bool
{
    return vd_state('rate', function (array &$d) use ($fail) {
        $now = time();
        foreach ($d as $ip => $r) {
            if ($r['t'] < $now - 600) {
                unset($d[$ip]);
            }
        }
        $ip = vd_ip();
        $d[$ip] ??= ['t' => $now, 'n' => 0, 'f' => 0];
        $d[$ip]['n']++;
        if ($fail) {
            $d[$ip]['f']++;
        }

        return $d[$ip]['f'] < 10 && $d[$ip]['n'] <= 400;
    });
}

function vd_secret(): ?string
{
    global $VD;
    $f = $VD['app'].'/.deploy-secret';
    $s = is_file($f) ? trim((string) file_get_contents($f)) : null;
    if (! $s) {
        $s = vd_env('DEPLOY_SECRET');
    }
    if (! $s && is_dir($VD['app']) && ! is_file($f)) {
        // Created so the file exists with safe permissions; set the real value with the upgrade page.
        $old = umask(0077);
        @file_put_contents($f, bin2hex(random_bytes(32))."\n");
        umask($old);
        @chmod($f, 0600);

        return null;
    }

    return ($s && strlen($s) >= 32) ? $s : null;
}

function vd_public_key(): ?string
{
    global $VD;
    $k = vd_env('DEPLOY_PUBLIC_KEY');
    if (! $k) {
        foreach ([$VD['app'], $VD['prev']] as $root) {
            $cfg = $root.'/config/deploy.php';
            if (is_file($cfg) && preg_match("/'public_key'\\s*=>\\s*env\\('DEPLOY_PUBLIC_KEY',\\s*'([A-Za-z0-9+\\/=]{40,})'\\)/", (string) file_get_contents($cfg), $m)) {
                $k = $m[1];
                break;
            }
        }
    }
    $raw = $k ? base64_decode($k, true) : false;

    return ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? $raw : null;
}

function vd_canonical_query(): string
{
    $q = $_GET;
    ksort($q);

    return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

/** Streams the request body to a temp file while hashing; returns [path, sha256hex, size]. */
function vd_read_body(int $max): array
{
    global $VD;
    vd_mkdir($VD['work'].'/tmp');
    $tmp = tempnam($VD['work'].'/tmp', 'body');
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $ctx = hash_init('sha256');
    $n = 0;
    while (! feof($in)) {
        $buf = fread($in, 1 << 20);
        if ($buf === false) {
            break;
        }
        $n += strlen($buf);
        if ($n > $max) {
            fclose($out);
            @unlink($tmp);
            vd_404();
        }
        hash_update($ctx, $buf);
        fwrite($out, $buf);
    }
    fclose($in);
    fclose($out);

    return [$tmp, hash_final($ctx), $n];
}

function vd_authenticate(int $maxBody): array
{
    $ts = $_SERVER['HTTP_X_VEGA_TIMESTAMP'] ?? '';
    $nonce = $_SERVER['HTTP_X_VEGA_NONCE'] ?? '';
    $sig = strtolower($_SERVER['HTTP_X_VEGA_SIGNATURE'] ?? '');
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (! ctype_digit($ts) || abs(time() - (int) $ts) > VD_MAX_SKEW || ! preg_match('/^[a-f0-9]{32,64}$/', $nonce)
        || ! preg_match('/^[a-f0-9]{64}$/', $sig) || $len > $maxBody) {
        vd_rate(true);
        vd_404();
    }
    $secret = vd_secret();
    if (! $secret) {
        vd_rate(true);
        vd_404();
    }
    [$tmp, $bodyHash, $size] = vd_read_body($maxBody);
    $expect = hash_hmac('sha256', "VEGA-DEPLOY-HMAC-V1\n{$ts}\n{$nonce}\n".vd_canonical_query()."\n{$bodyHash}", $secret);
    if (! hash_equals($expect, $sig)) {
        @unlink($tmp);
        vd_rate(true);
        vd_404();
    }
    $fresh = vd_state('nonces', function (array &$d) use ($nonce) {
        $now = time();
        foreach ($d as $k => $t) {
            if ($t < $now - 2 * VD_MAX_SKEW) {
                unset($d[$k]);
            }
        }
        if (isset($d[$nonce])) {
            return false;
        }
        $d[$nonce] = $now;

        return true;
    });
    if (! $fresh) {
        @unlink($tmp);
        vd_rate(true);
        vd_404();
    }

    return [$tmp, $size];
}

function vd_manifest(string $root): array
{
    $f = $root.'/deploy-manifest.json';

    return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
}

function vd_limits(): array
{
    $post = vd_bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX;
    $maxChunk = (int) (vd_env('DEPLOY_MAX_CHUNK') ?: 0) ?: 64 << 20;

    return [
        'max_bytes' => (int) (vd_env('DEPLOY_MAX_BYTES') ?: VD_DEFAULT_MAX_BYTES),
        'max_chunk' => max(1 << 16, min($maxChunk, (int) ($post * 0.9))),
        'post_max_size' => ini_get('post_max_size'), 'upload_max_filesize' => ini_get('upload_max_filesize'),
    ];
}

// ------------------------------------------------------------------ deploy steps

function vd_maintenance(bool $on): void
{
    global $VD;
    $dir = $VD['app'].'/storage/framework';
    $stub = $dir.'/maintenance.php';
    if ($on) {
        if (is_dir($dir)) {
            file_put_contents($stub, "<?php\nhttp_response_code(503);\nheader('Retry-After: 60');\nheader('Content-Type: text/html; charset=utf-8');\necho '<!doctype html><title>Maintenance</title><h1>Updating, back in a minute.</h1>';\nexit;\n");
            vd_log('Maintenance mode ON.');
        }
    } else {
        @unlink($stub);
        @unlink($dir.'/down');
        vd_log('Maintenance mode OFF.');
    }
}

function vd_clear_compiled(string $root): void
{
    foreach (glob($root.'/bootstrap/cache/*.php') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($root.'/storage/framework/views/*.php') ?: [] as $f) {
        @unlink($f);
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

function vd_extract(string $zipPath, string $stage): array
{
    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Not a valid zip.');
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = (string) $zip->getNameIndex($i);
        if ($n === '' || str_contains($n, '\\') || str_starts_with($n, '/') || preg_match('#(^|/)\.\.(/|$)#', $n)
            || ! preg_match('#^(laravel/|public_html/|README_UPGRADE\.md$|deploy-manifest\.json$)#', $n)) {
            $zip->close();
            throw new RuntimeException('Unsafe or unexpected path in package: '.substr($n, 0, 120));
        }
        if (preg_match('#^laravel/(\.env$|\.deploy-secret$|storage/)#', $n)) {
            $zip->close();
            throw new RuntimeException('Package must not contain laravel/.env or laravel/storage.');
        }
    }
    vd_rmrf($stage);
    vd_mkdir($stage);
    if (! $zip->extractTo($stage)) {
        $zip->close();
        throw new RuntimeException('Extraction failed.');
    }
    $zip->close();
    $m = vd_manifest($stage);
    if (($m['format'] ?? null) !== 1 || ! is_file($stage.'/laravel/vendor/autoload.php') || ! is_file($stage.'/laravel/bootstrap/app.php')) {
        throw new RuntimeException('Package is missing deploy-manifest.json or the laravel app.');
    }
    foreach ($m['seeders'] ?? [] as $s) {
        if (! is_string($s) || ! preg_match('/^[A-Za-z0-9_]+$/', $s) || ! is_file($stage.'/laravel/database/seeders/'.$s.'.php')) {
            throw new RuntimeException('Bad seeder in manifest.');
        }
    }
    vd_log('Extracted package '.($m['version'] ?? '?').' to staging.');

    return $m;
}

/** Keep SiteGround / host lines (PHP handler, SetEnv, php_value, other BEGIN/END blocks) and replace the Vega ERP block. */
function vd_merge_htaccess(string $current, string $package): string
{
    $block = preg_match('/# BEGIN VEGA-ERP.*?# END VEGA-ERP\R?/s', $package, $m) ? $m[0] : "# BEGIN VEGA-ERP\n".rtrim($package)."\n# END VEGA-ERP\n";
    if (str_contains($current, '# BEGIN VEGA-ERP')) {
        return preg_replace('/# BEGIN VEGA-ERP.*?# END VEGA-ERP\R?/s', str_replace('\\', '\\\\', str_replace('$', '\$', $block)), $current, 1);
    }
    $keep = [];
    $inBlock = false;
    foreach (preg_split('/\R/', $current) as $line) {
        if (preg_match('/^\s*#\s*BEGIN\s+(?!VEGA-ERP)/i', $line)) {
            $inBlock = true;
        }
        if ($inBlock || preg_match('/^\s*(AddHandler|SetEnv|php_value|php_flag|AddType|SetHandler)\b/i', $line)) {
            $keep[] = $line;
        }
        if ($inBlock && preg_match('/^\s*#\s*END\b/i', $line)) {
            $inBlock = false;
        }
    }

    return ($keep ? implode("\n", $keep)."\n\n" : '').$block;
}

/** Copy package public_html into the web root; records overwritten/created files so a rollback can restore them. */
function vd_sync_public(string $src): void
{
    global $VD;
    $bak = $VD['work'].'/public-prev';
    vd_rmrf($bak);
    vd_mkdir($bak);
    $created = [];
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($src) + 1);
        if ($rel === 'deploy.php' || $rel === 'storage' || str_starts_with($rel, 'storage/') || preg_match('#^upgrade-[^/]*\.php$#', $rel)) {
            continue;
        }
        $dst = $VD['pub'].'/'.$rel;
        if ($f->isDir()) {
            if (! is_dir($dst)) {
                vd_mkdir($dst);
                @chmod($dst, 0755);
                $created[] = $rel.'/';
            }
            continue;
        }
        if (is_file($dst)) {
            vd_mkdir(dirname($bak.'/'.$rel));
            copy($dst, $bak.'/'.$rel);
        } else {
            $created[] = $rel;
        }
        if ($rel === '.htaccess') {
            $merged = vd_merge_htaccess(is_file($dst) ? (string) file_get_contents($dst) : '', (string) file_get_contents($f->getPathname()));
            file_put_contents($dst.'.tmp', $merged);
            rename($dst.'.tmp', $dst);
        } else {
            copy($f->getPathname(), $dst.'.vdtmp');
            rename($dst.'.vdtmp', $dst);
        }
        @chmod($dst, 0644);
        $n++;
    }
    file_put_contents($VD['work'].'/public-prev.json', json_encode(['created' => $created]));
    vd_log("Synced {$n} public_html files (kept deploy.php, storage link, host .htaccess lines).");
    if (! file_exists($VD['pub'].'/storage') && is_dir($VD['app'].'/storage/app/public')) {
        @symlink($VD['app'].'/storage/app/public', $VD['pub'].'/storage') ? vd_log('Created public_html/storage link.') : vd_log('WARNING: could not create public_html/storage link.');
    }
}

function vd_restore_public(): void
{
    global $VD;
    $bak = $VD['work'].'/public-prev';
    if (! is_dir($bak)) {
        return;
    }
    $meta = json_decode((string) @file_get_contents($VD['work'].'/public-prev.json'), true) ?: [];
    foreach (array_reverse($meta['created'] ?? []) as $rel) {
        str_ends_with($rel, '/') ? @rmdir($VD['pub'].'/'.rtrim($rel, '/')) : @unlink($VD['pub'].'/'.$rel);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($bak, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($bak) + 1);
        vd_mkdir(dirname($VD['pub'].'/'.$rel));
        copy($f->getPathname(), $VD['pub'].'/'.$rel);
    }
    vd_rmrf($bak);
    @unlink($VD['work'].'/public-prev.json');
    vd_log('Restored previous public_html files.');
}

/** Carry the server-only bits (storage/, .env, .deploy-secret) from one app dir to another. */
function vd_carry(string $from, string $to): void
{
    if (is_dir($from.'/storage')) {
        vd_rmrf($to.'/storage');
        if (! rename($from.'/storage', $to.'/storage')) {
            throw new RuntimeException('Could not move storage/.');
        }
    }
    foreach (['.env', '.deploy-secret'] as $f) {
        if (is_file($from.'/'.$f)) {
            copy($from.'/'.$f, $to.'/'.$f);
            @chmod($to.'/'.$f, 0600);
        }
    }
}

function vd_artisan(array $manifest): void
{
    global $VD;
    $base = $VD['app'];
    vd_clear_compiled($base);
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $run = function (string $cmd, array $args = []) use ($kernel) {
        $code = $kernel->call($cmd, $args);
        $out = trim($kernel->output());
        vd_log("artisan {$cmd} (exit {$code})".($out !== '' ? ': '.mb_substr(preg_replace('/\s+/', ' ', $out), 0, 600) : ''));
        if ($code !== 0) {
            throw new RuntimeException("artisan {$cmd} failed");
        }
    };
    $run('migrate', ['--force' => true]);
    foreach ($manifest['seeders'] ?? [] as $s) {
        $run('db:seed', ['--class' => 'Database\\Seeders\\'.$s, '--force' => true]);
    }
    $run('optimize:clear');
    $run('config:cache');
    $run('route:cache');
    $run('view:cache');
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
}

function vd_health(): bool
{
    $urls = array_filter([vd_env('DEPLOY_HEALTH_URL')]);
    if (! $urls) {
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        $urls = ["{$scheme}://{$host}/up", "{$scheme}://{$host}/admin/login"];
    } else {
        $urls = array_map('trim', explode(',', $urls[0]));
    }
    foreach ($urls as $u) {
        for ($try = 1; $try <= 3; $try++) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => ['Cache-Control: no-cache']]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            vd_log("Health {$u} -> {$code}");
            if ($code === 200) {
                return true;
            }
            sleep(1);
        }
    }

    return false;
}

/** Restore laravel-prev as the live app. Filesystem only (safe even after a fatal error in the new code). */
function vd_rollback(string $why): bool
{
    global $VD;
    vd_log('ROLLBACK: '.$why);
    if (! is_dir($VD['prev'].'/vendor')) {
        vd_log('No laravel-prev to roll back to.');

        return false;
    }
    vd_maintenance(true);
    vd_rmrf($VD['failed']);
    if (is_dir($VD['app']) && ! rename($VD['app'], $VD['failed'])) {
        vd_log('Could not move current app aside.');

        return false;
    }
    rename($VD['prev'], $VD['app']);
    vd_carry($VD['failed'], $VD['app']);
    vd_restore_public();
    vd_clear_compiled($VD['app']);
    vd_maintenance(false);
    $ok = vd_health();
    vd_log($ok ? 'Rolled back to '.(vd_manifest($VD['app'])['version'] ?? 'previous version').'.' : 'WARNING: health check still failing after rollback.');
    vd_log('Note: database migrations are not reversed by a rollback.');

    return $ok;
}

function vd_deploy(string $zipPath): array
{
    global $VD;
    $stage = $VD['work'].'/stage';
    $m = vd_extract($zipPath, $stage);
    vd_maintenance(true);
    // one previous copy only
    vd_rmrf($VD['prev']);
    vd_rmrf($VD['failed']);
    vd_mkdir($VD['work'].'/env-backups');
    if (is_file($VD['app'].'/.env')) {
        copy($VD['app'].'/.env', $VD['work'].'/env-backups/env-'.date('Ymd-His'));
        @chmod($VD['work'].'/env-backups/env-'.date('Ymd-His'), 0600);
        $old = glob($VD['work'].'/env-backups/env-*') ?: [];
        rsort($old);
        foreach (array_slice($old, 5) as $f) {
            @unlink($f);
        }
    }
    $VD['busy'] = true;
    if (! rename($VD['app'], $VD['prev'])) {
        $VD['busy'] = false;
        vd_maintenance(false);
        throw new RuntimeException('Could not move laravel/ to laravel-prev/.');
    }
    if (! rename($stage.'/laravel', $VD['app'])) {
        rename($VD['prev'], $VD['app']);
        $VD['busy'] = false;
        vd_maintenance(false);
        throw new RuntimeException('Could not move the new app into place.');
    }
    vd_carry($VD['prev'], $VD['app']);
    // carry stays reversible: laravel-prev keeps its own copy of .env; storage moves back on rollback
    copy($VD['app'].'/.env', $VD['prev'].'/.env');
    copy($stage.'/deploy-manifest.json', $VD['app'].'/deploy-manifest.json');
    vd_log('Swapped laravel/ (previous kept in laravel-prev/).');
    vd_maintenance(true); // stub lives in storage, which moved
    try {
        vd_sync_public($stage.'/public_html');
        vd_artisan($m);
    } catch (Throwable $e) {
        $VD['busy'] = false;
        vd_rollback('step failed: '.$e->getMessage());
        vd_rmrf($stage);

        return ['ok' => false, 'rolled_back' => true, 'error' => $e->getMessage()];
    }
    vd_maintenance(false);
    $VD['busy'] = false;
    vd_rmrf($stage);
    if (! vd_health()) {
        vd_rollback('health check failed');
        vd_rmrf($VD['failed'].'/storage');

        return ['ok' => false, 'rolled_back' => true, 'error' => 'health check failed'];
    }
    vd_log('Deployed '.($m['version'] ?? '?').'.');

    return ['ok' => true, 'version' => $m['version'] ?? null];
}

function vd_status(): array
{
    global $VD;

    return [
        'ok' => true, 'version' => vd_manifest($VD['app'])['version'] ?? 'unknown (pre-deploy package)',
        'previous' => is_dir($VD['prev']) ? (vd_manifest($VD['prev'])['version'] ?? 'unknown') : null,
        'maintenance' => is_file($VD['app'].'/storage/framework/maintenance.php'),
        'php' => PHP_VERSION, 'sodium' => function_exists('sodium_crypto_sign_verify_detached'), 'zip' => class_exists('ZipArchive'),
        'public_key' => vd_public_key() !== null, 'limits' => vd_limits(),
    ];
}

// ------------------------------------------------------------------ request handling

register_shutdown_function(function () {
    global $VD;
    $e = error_get_last();
    if ($VD['busy'] && $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $VD['busy'] = false;
        vd_rollback('fatal error: '.$e['message']);
        if (! headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['ok' => false, 'rolled_back' => true, 'error' => 'fatal error', 'log' => $VD['log']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ! function_exists('sodium_crypto_sign_verify_detached') || ! function_exists('curl_init')) {
    vd_404();
}
try {
    if (! vd_rate()) {
        vd_404();
    }
} catch (Throwable) {
    vd_404();
}
$limits = vd_limits();
$action = (string) ($_GET['action'] ?? '');
[$body, $bodySize] = vd_authenticate($action === 'chunk' ? $limits['max_chunk'] : 65536);

// ---- authenticated from here ----
@set_time_limit(900);
ignore_user_abort(true);
$id = (string) ($_GET['id'] ?? '');
$up = $VD['work'].'/uploads';
$validId = (bool) preg_match('/^[a-f0-9]{32}$/', $id);

try {
    if ($action === 'status') {
        @unlink($body);
        vd_json(vd_status());
    }
    if (in_array($action, ['init', 'chunk', 'finalize'], true) && ! $validId) {
        @unlink($body);
        vd_json(['ok' => false, 'error' => 'bad id'], 400);
    }
    if ($action === 'init') {
        @unlink($body);
        $size = (int) ($_GET['size'] ?? 0);
        $sha = strtolower((string) ($_GET['sha256'] ?? ''));
        $sig = base64_decode((string) ($_GET['sig'] ?? ''), true);
        $pk = vd_public_key();
        if ($size < 1 || $size > $limits['max_bytes']) {
            vd_json(['ok' => false, 'error' => 'size over limit '.$limits['max_bytes']], 413);
        }
        if (! $pk || ! preg_match('/^[a-f0-9]{64}$/', $sha) || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($sig, "VEGA-DEPLOY-PKG-V1\n{$sha}", $pk)) {
            vd_json(['ok' => false, 'error' => 'package signature invalid'], 403);
        }
        vd_mkdir($up);
        foreach (glob($up.'/*') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        file_put_contents("{$up}/{$id}.json", json_encode(['size' => $size, 'sha256' => $sha, 'sig' => base64_encode($sig)]));
        file_put_contents("{$up}/{$id}.part", '');
        vd_json(['ok' => true, 'id' => $id, 'max_chunk' => $limits['max_chunk']]);
    }
    if ($action === 'chunk') {
        $meta = json_decode((string) @file_get_contents("{$up}/{$id}.json"), true);
        $part = "{$up}/{$id}.part";
        if (! $meta || ! is_file($part)) {
            @unlink($body);
            vd_json(['ok' => false, 'error' => 'unknown upload'], 404);
        }
        clearstatcache();
        $have = filesize($part);
        $offset = (int) ($_GET['offset'] ?? -1);
        if ($offset !== $have || $have + $bodySize > $meta['size']) {
            @unlink($body);
            vd_json(['ok' => false, 'error' => 'offset mismatch', 'received' => $have], 409);
        }
        $in = fopen($body, 'rb');
        $out = fopen($part, 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        @unlink($body);
        clearstatcache();
        vd_json(['ok' => true, 'received' => filesize($part)]);
    }
    @unlink($body);
    if ($action === 'finalize' || $action === 'rollback') {
        $lock = fopen($VD['work'].'/deploy.lock', 'c');
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            vd_json(['ok' => false, 'error' => 'another deploy is running'], 423);
        }
        if ($action === 'rollback') {
            $ok = vd_rollback('requested');
            vd_json(['ok' => $ok, 'version' => vd_manifest($VD['app'])['version'] ?? null], $ok ? 200 : 500);
        }
        $meta = json_decode((string) @file_get_contents("{$up}/{$id}.json"), true);
        $part = "{$up}/{$id}.part";
        clearstatcache();
        if (! $meta || ! is_file($part) || filesize($part) !== $meta['size']) {
            vd_json(['ok' => false, 'error' => 'upload incomplete'], 409);
        }
        $sha = hash_file('sha256', $part);
        if (! hash_equals($meta['sha256'], $sha) || ! ($pk = vd_public_key())
            || ! sodium_crypto_sign_verify_detached(base64_decode($meta['sig']), "VEGA-DEPLOY-PKG-V1\n{$sha}", $pk)) {
            @unlink($part);
            vd_json(['ok' => false, 'error' => 'package hash/signature mismatch'], 403);
        }
        vd_log('Package verified (sha256 '.substr($sha, 0, 12).'…, Ed25519 OK).');
        $res = vd_deploy($part);
        @unlink($part);
        @unlink("{$up}/{$id}.json");
        file_put_contents($VD['work'].'/last-deploy.json', json_encode($res + ['at' => date('c'), 'log' => $VD['log']]));
        vd_json($res + ['status' => vd_status()], $res['ok'] ? 200 : 500);
    }
    vd_json(['ok' => false, 'error' => 'unknown action'], 400);
} catch (Throwable $e) {
    if ($VD['busy']) {
        $VD['busy'] = false;
        vd_rollback('error: '.$e->getMessage());
    }
    vd_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
