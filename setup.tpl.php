<?php
/**
 * Vega ERP (BrandWorx Light ERP) one-time installer. Deletes itself after a successful run.
 * Visit: https://vegatest1.distrosku.com/setup-__TOKEN__.php?token=__TOKEN__
 */
declare(strict_types=1);
@ini_set('display_errors', '0');
error_reporting(0);
@set_time_limit(300);

const SETUP_TOKEN = '__TOKEN__';
$base = realpath(__DIR__ . '/../laravel');

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function envq(string $v): string { return '"' . str_replace(['\\', '"', "\n", "\r", '$'], ['\\\\', '\\"', '', '', '\\$'], $v) . '"'; }

if (!isset($_GET['token']) || !is_string($_GET['token']) || !hash_equals(SETUP_TOKEN, $_GET['token'])) {
    http_response_code(404);
    exit('Not found');
}

$log = [];
$errors = [];
$done = false;
$deleted = false;

if ($base === false || !is_file($base . '/vendor/autoload.php')) {
    $errors[] = 'Laravel app folder not found at ../laravel (expected ~/www/vegatest1.distrosku.com/laravel).';
}

if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = fn($k, $d = '') => trim((string) ($_POST[$k] ?? $d));
    $db = ['name' => $in('db_name'), 'user' => $in('db_user'), 'pass' => (string) ($_POST['db_pass'] ?? ''), 'host' => $in('db_host', 'localhost') ?: 'localhost'];
    $appUrl = rtrim($in('app_url', 'https://vegatest1.distrosku.com'), '/');
    $admin = ['name' => $in('admin_name'), 'email' => strtolower($in('admin_email')), 'pass' => (string) ($_POST['admin_pass'] ?? '')];
    $seedDemo = !empty($_POST['seed_demo']);
    $driver = (isset($_POST['db_driver']) && $_POST['db_driver'] === 'sqlite' && getenv('DISTROSKU_ALLOW_SQLITE') === '1') ? 'sqlite' : 'mysql';

    if ($driver === 'mysql' && ($db['name'] === '' || $db['user'] === '')) $errors[] = 'Database name and user are required.';
    if (!filter_var($appUrl, FILTER_VALIDATE_URL)) $errors[] = 'APP_URL is not a valid URL.';
    if ($admin['name'] === '') $errors[] = 'Admin name is required.';
    if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Admin email is invalid.';
    if (strlen($admin['pass']) < 12) $errors[] = 'Admin password must be at least 12 characters.';

    if (!$errors && $driver === 'mysql') {
        try {
            new PDO('mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $log[] = 'Database connection OK.';
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to the database. Check name, user, password and host.';
        }
    }

    if (!$errors) {
        try {
            // 1. Write .env
            $tpl = file_get_contents($base . '/.env.example');
            $set = [
                'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => '', 'APP_URL' => $appUrl,
                'DB_CONNECTION' => $driver,
                'DB_HOST' => envq($db['host']), 'DB_PORT' => '3306',
                'DB_DATABASE' => $driver === 'sqlite' ? envq($base . '/database/database.sqlite') : envq($db['name']),
                'DB_USERNAME' => envq($db['user']), 'DB_PASSWORD' => envq($db['pass']),
            ];
            foreach ($set as $k => $v) {
                $tpl = preg_match('/^' . $k . '=.*$/m', $tpl)
                    ? preg_replace_callback('/^' . $k . '=.*$/m', fn() => $k . '=' . $v, $tpl)
                    : $tpl . "\n" . $k . '=' . $v;
            }
            if ($driver === 'sqlite' && !is_file($base . '/database/database.sqlite')) touch($base . '/database/database.sqlite');
            if (file_put_contents($base . '/.env', $tpl) === false) throw new RuntimeException('Could not write .env');
            @chmod($base . '/.env', 0640);
            $log[] = '.env written.';

            // Clear stale caches before booting
            foreach (glob($base . '/bootstrap/cache/*.php') ?: [] as $f) {
                if (!in_array(basename($f), ['packages.php', 'services.php'], true)) @unlink($f);
            }

            // 2. Boot Laravel
            require $base . '/vendor/autoload.php';
            $app = require $base . '/bootstrap/app.php';
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();

            $run = function (string $cmd, array $args = []) use ($kernel, &$log) {
                $code = $kernel->call($cmd, $args);
                $out = trim($kernel->output());
                $log[] = '$ php artisan ' . $cmd . ' (exit ' . $code . ')' . ($out !== '' ? "\n" . $out : '');
                if ($code !== 0) throw new RuntimeException('Command failed: ' . $cmd);
            };

            $run('key:generate', ['--force' => true]);
            // key:generate updates config in memory; make sure encrypter uses it
            $app->forgetInstance('encrypter');
            $run('migrate', ['--force' => true]);
            $run('db:seed', ['--class' => 'Database\\Seeders\\CoreSeeder', '--force' => true]);
            if ($seedDemo) {
                $run('db:seed', ['--class' => 'Database\\Seeders\\DemoSeeder', '--force' => true]);
            }

            // 3. Storage link (public_html/storage -> laravel/storage/app/public)
            $target = $base . '/storage/app/public';
            $link = __DIR__ . '/storage';
            if (is_link($link) || is_dir($link)) {
                $log[] = 'public_html/storage already exists.';
            } else {
                try { $run('storage:link'); } catch (Throwable $e) { $log[] = 'artisan storage:link failed, trying symlink().'; }
                if (!is_link($link) && !is_dir($link)) {
                    if (function_exists('symlink') && @symlink($target, $link)) $log[] = 'Created public_html/storage symlink via PHP.';
                    else $log[] = 'WARNING: could not create public_html/storage symlink. Create it in Site Tools or ask support; uploads to the public disk will not be web-visible until then.';
                }
            }

            // 4. Admin user
            $userClass = App\Models\User::class;
            $user = $userClass::where('email', $admin['email'])->first() ?? new $userClass();
            $user->name = $admin['name'];
            $user->email = $admin['email'];
            $user->password = Illuminate\Support\Facades\Hash::make($admin['pass']);
            $user->email_verified_at = now();
            $user->role = 'brandworx_admin';
            $user->active = true;
            $user->save();
            $log[] = 'Admin user saved: ' . $admin['email'];

            // 5. Caches
            $run('config:cache');
            $run('route:cache');
            $run('view:cache');

            // 6. Permissions
            foreach (['storage', 'bootstrap/cache'] as $dir) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/' . $dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                @chmod($base . '/' . $dir, 0775);
                foreach ($it as $f) { if (!$f->isLink()) @chmod($f->getPathname(), $f->isDir() ? 0775 : 0664); }
            }
            $log[] = 'Permissions set (775 dirs / 664 files) on storage and bootstrap/cache.';

            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Setup stopped: ' . $e->getMessage();
        }
        if ($done && getenv('DISTROSKU_KEEP_SETUP') !== '1') {
            $deleted = @unlink(__FILE__);
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Vega ERP setup</title>
<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem}label{display:block;margin:.6rem 0 .2rem;font-weight:600}input{width:100%;padding:.5rem;box-sizing:border-box}button{margin-top:1rem;padding:.7rem 1.4rem}pre{background:#111;color:#eee;padding:1rem;white-space:pre-wrap;font-size:.85rem}.err{color:#b00}.ok{color:#070}</style></head><body>
<h1>Vega ERP one-time setup</h1>
<?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
<?php if ($log): ?><h2>Log</h2><pre><?= h(implode("\n", $log)) ?></pre><?php endif; ?>
<?php if ($done): ?>
  <p class="ok"><strong>Setup complete.</strong> <a href="<?= h($appUrl . '/admin') ?>">Go to /admin</a></p>
  <p><?= $deleted ? 'This setup file has deleted itself.' : '<strong class="err">Could not delete this setup file. Delete it now in File Manager.</strong>' ?></p>
<?php elseif (!$errors || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
<form method="post" action="?token=<?= h(SETUP_TOKEN) ?>" autocomplete="off">
  <?php if (getenv('DISTROSKU_ALLOW_SQLITE') === '1'): ?><label>DB driver</label><input name="db_driver" value="sqlite"><?php endif; ?>
  <label>Database name</label><input name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>">
  <label>Database user</label><input name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>">
  <label>Database password</label><input type="password" name="db_pass">
  <label>Database host</label><input name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>">
  <label>APP_URL</label><input name="app_url" value="<?= h($_POST['app_url'] ?? 'https://vegatest1.distrosku.com') ?>">
  <label>Admin name</label><input name="admin_name" value="<?= h($_POST['admin_name'] ?? '') ?>">
  <label>Admin email</label><input type="email" name="admin_email" value="<?= h($_POST['admin_email'] ?? '') ?>">
  <label>Admin password (12+ chars)</label><input type="password" name="admin_pass">
  <label style="font-weight:400"><input type="checkbox" name="seed_demo" value="1" style="width:auto" checked> Load clearly-fake TEST demo data (products, customers, users). Chart of accounts, 2 warehouses and starter rules always load.</label>
  <button type="submit">Install</button>
</form>
<?php endif; ?>
</body></html>
