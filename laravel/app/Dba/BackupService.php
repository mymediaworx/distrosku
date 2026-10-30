<?php

namespace App\Dba;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * 0.20.8: instance backup / restore.
 * Archive = <instance>-YYYYmmdd-His-<trigger>.zip + <same>.zip.sha256, stored OUTSIDE the web roots.
 * Inside: manifest.json (sha256 of every file), db/<table>.jsonl (portable, used for restore on any driver),
 * db/dump.sql (mysqldump, when the binary is available; for disaster recovery by hand), env/.env, files/storage/app/**.
 */
class BackupService
{
    public const FORMAT = 'distrosku-backup/1';

    /** tables never backed up / restored (transient, or the link to the manager itself) */
    public const SKIP_TABLES = ['cache', 'cache_locks', 'sessions', 'dba_nonces', 'jobs', 'job_batches'];

    /** settings that belong to this instance and survive a restore (e.g. from another instance) */
    public const KEEP_SETTINGS = ['dba_secret', 'dba_suspended', 'dba_suspended_reason', 'dba_suspended_at', 'dba_resumed_at', 'install_id'];

    public static bool $useMysqldump = true;

    public function instance(): string
    {
        return Str::slug((string) (config('erp.dba.instance') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'erp')) ?: 'erp';
    }

    public function dir(): string
    {
        $base = config('erp.dba.backup_path')
            ?: rtrim((string) (getenv('HOME') ?: dirname(base_path(), 3)), '/').'/erp-backups/'.$this->instance();
        File::ensureDirectoryExists($base, 0700);

        return rtrim($base, '/');
    }

    /** @return array{file:string,path:string,sha256:string,size:int,created_at:string,trigger:string,tables:int,rows:int} */
    public function create(string $trigger = 'manual', ?string $by = null, bool $prune = true): array
    {
        \App\Support\TimeLimit::extend(900);
        $trigger = Str::slug($trigger) ?: 'manual';
        $stamp = now()->utc();
        $name = $this->instance().'-'.$stamp->format('Ymd-His').'-'.$trigger.'.zip';
        $path = $this->dir().'/'.$name;
        $tmp = sys_get_temp_dir().'/erpbk-'.Str::random(10);
        File::ensureDirectoryExists($tmp.'/db');
        $files = [];
        $tables = [];
        try {
            foreach ($this->tables() as $t) {
                $fh = fopen($f = $tmp.'/db/'.$t.'.jsonl', 'w');
                $n = 0;
                foreach (DB::table($t)->cursor() as $row) {
                    fwrite($fh, json_encode(self::encodeRow((array) $row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                    $n++;
                }
                fclose($fh);
                $tables[$t] = $n;
                $files['db/'.$t.'.jsonl'] = $f;
            }
            if ($sql = $this->mysqldump($tmp.'/db/dump.sql')) {
                $files['db/dump.sql'] = $sql;
            }
            if (is_file(base_path('.env'))) {
                $files['env/.env'] = base_path('.env');
            }
            $app = storage_path('app');
            if (is_dir($app)) {
                foreach (File::allFiles($app, true) as $sf) {
                    $real = $sf->getRealPath();
                    if ($real && ! str_starts_with($real, $this->dir())) {
                        $files['files/storage/app/'.str_replace('\\', '/', $sf->getRelativePathname())] = $real;
                    }
                }
            }
            $manifest = ['format' => self::FORMAT, 'instance' => $this->instance(), 'erp_version' => config('erp.version'),
                'created_at' => $stamp->toIso8601String(), 'trigger' => $trigger, 'by' => $by, 'driver' => DB::connection()->getDriverName(),
                'last_migration' => $this->lastMigration(), 'tables' => $tables,
                'files' => array_map(fn ($p) => hash_file('sha256', $p), $files)];
            $zip = new ZipArchive;
            if ($zip->open($path.'.part', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot write '.$path);
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            foreach ($files as $inZip => $src) {
                $zip->addFile($src, $inZip);
            }
            $zip->close();
            rename($path.'.part', $path);
            @chmod($path, 0600);
            $sha = hash_file('sha256', $path);
            file_put_contents($path.'.sha256', $sha.'  '.$name."\n");
        } finally {
            File::deleteDirectory($tmp);
        }
        Setting::put('dba_last_backup', json_encode(['file' => $name, 'sha256' => $sha, 'at' => $stamp->toIso8601String(), 'status' => 'ok']));
        InstanceState::log('backup', $by, ['file' => $name, 'trigger' => $trigger]);
        if ($prune) {
            $this->prune();
        }

        return ['file' => $name, 'path' => $path, 'sha256' => $sha, 'size' => filesize($path), 'created_at' => $stamp->toIso8601String(),
            'trigger' => $trigger, 'tables' => count($tables), 'rows' => array_sum($tables)];
    }

    /** @return list<array{file:string,size:int,sha256:?string,created_at:string,trigger:string,status:string}> newest first */
    public function list(bool $verify = false): array
    {
        $out = [];
        foreach (glob($this->dir().'/*.zip') ?: [] as $p) {
            $name = basename($p);
            $side = is_file($p.'.sha256') ? strtok((string) file_get_contents($p.'.sha256'), ' ') : null;
            $status = $side === null ? 'no-checksum' : ($verify ? (hash_file('sha256', $p) === $side ? 'verified' : 'corrupt') : 'ok');
            $out[] = ['file' => $name, 'size' => filesize($p), 'sha256' => $side ?: null, 'created_at' => $this->stampOf($name)?->toIso8601String() ?? date(DATE_ATOM, filemtime($p)),
                'trigger' => $this->triggerOf($name), 'status' => $status];
        }
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    /** Resolve a backup by file name (in this instance's dir) or by absolute path (restore-into-another-instance). */
    public function resolve(string $fileOrPath): string
    {
        $p = str_contains($fileOrPath, '/') ? $fileOrPath : $this->dir().'/'.basename($fileOrPath);
        if (! is_file($p) || ! str_ends_with($p, '.zip')) {
            throw new RuntimeException('Backup not found: '.basename($fileOrPath));
        }

        return $p;
    }

    /** Verify the archive checksum and every file hash in the manifest. Returns the manifest. */
    public function verify(string $path): array
    {
        if (! is_file($path.'.sha256')) {
            throw new RuntimeException('Checksum file missing for '.basename($path));
        }
        if (! hash_equals(strtok((string) file_get_contents($path.'.sha256'), ' '), hash_file('sha256', $path))) {
            throw new RuntimeException('Checksum mismatch: '.basename($path).' is corrupt.');
        }
        $zip = $this->open($path);
        $m = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (($m['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Not a DistroSKU backup.');
        }
        foreach ($m['files'] as $f => $sha) {
            $data = $zip->getFromName($f);
            if ($data === false || ! hash_equals($sha, hash('sha256', $data))) {
                throw new RuntimeException("File {$f} fails its checksum.");
            }
        }
        $zip->close();

        return $m;
    }

    /**
     * Restore a backup. Always takes a safety backup first (unless $safety=false, tests only).
     * Database: every backed-up table that exists here is emptied and reloaded (columns matched by name).
     * Files: storage/app files are written back (nothing extra is deleted). .env only with $withEnv.
     * Instance-link settings (KEEP_SETTINGS) are preserved, so restoring another instance's backup never unlinks this one.
     */
    public function restore(string $fileOrPath, bool $withEnv = false, bool $safety = true, ?string $by = null): array
    {
        \App\Support\TimeLimit::extend(1800);
        $path = $this->resolve($fileOrPath);
        $m = $this->verify($path);
        $safetyFile = $safety ? $this->create('pre-restore', $by, false)['file'] : null;
        $kept = Schema::hasTable('settings') ? DB::table('settings')->whereIn('key', self::KEEP_SETTINGS)->get()->map(fn ($r) => (array) $r)->all() : [];
        $zip = $this->open($path);
        $local = array_flip($this->tables());
        $restored = [];
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($zip, $m, $local, $kept, &$restored) {
                if (DB::getDriverName() === 'sqlite') {
                    DB::statement('PRAGMA defer_foreign_keys = ON'); // effective even inside an outer transaction
                }
                foreach ($m['tables'] as $t => $count) {
                    if (! isset($local[$t])) {
                        continue; // table no longer exists in this version
                    }
                    $cols = array_flip(Schema::getColumnListing($t));
                    DB::table($t)->delete();
                    $batch = [];
                    $n = 0;
                    foreach (preg_split('/\n/', (string) $zip->getFromName('db/'.$t.'.jsonl'), -1, PREG_SPLIT_NO_EMPTY) as $line) {
                        $batch[] = array_intersect_key(self::decodeRow(json_decode($line, true, 512, JSON_THROW_ON_ERROR)), $cols);
                        if (count($batch) >= 200) {
                            DB::table($t)->insert($batch);
                            $n += count($batch);
                            $batch = [];
                        }
                    }
                    if ($batch) {
                        DB::table($t)->insert($batch);
                        $n += count($batch);
                    }
                    $restored[$t] = $n;
                }
                if (isset($m['tables']['settings'])) {
                    DB::table('settings')->whereIn('key', self::KEEP_SETTINGS)->delete();
                    foreach ($kept as $row) {
                        DB::table('settings')->insert($row);
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'files/storage/app/') && ! str_contains($name, '..')) {
                $dest = storage_path('app/'.substr($name, strlen('files/storage/app/')));
                File::ensureDirectoryExists(dirname($dest));
                file_put_contents($dest, $zip->getFromIndex($i));
                $files++;
            } elseif ($name === 'env/.env' && $withEnv) {
                copy(base_path('.env'), base_path('.env.pre-restore')) || true;
                file_put_contents(base_path('.env'), $zip->getFromIndex($i));
            }
        }
        $zip->close();
        InstanceState::log('restore', $by, ['file' => basename($path), 'from_instance' => $m['instance'], 'safety_backup' => $safetyFile]);

        return ['file' => basename($path), 'from_instance' => $m['instance'], 'from_version' => $m['erp_version'], 'safety_backup' => $safetyFile,
            'tables' => count($restored), 'rows' => array_sum($restored), 'files' => $files,
            'version_warning' => ($m['erp_version'] ?? null) !== config('erp.version') ? 'Backup was taken on '.$m['erp_version'].'; run migrations if needed.' : null];
    }

    /** Retention: newest backup of each of the last 14 days that have backups + newest of each of 8 earlier ISO weeks. */
    public function prune(int $daily = 14, int $weekly = 8): array
    {
        $keep = [];
        $days = [];
        $weeks = [];
        foreach ($this->list() as $b) {
            $at = Carbon::parse($b['created_at']);
            $d = $at->format('Y-m-d');
            $w = $at->format('o-W');
            if (count($days) < $daily && ! isset($days[$d])) {
                $days[$d] = $keep[$b['file']] = true;
                $weeks[$w] ??= false; // a week already represented by a daily does not use a weekly slot
            } elseif (isset($days[$d])) {
                continue;
            } elseif (! isset($weeks[$w]) && count(array_filter($weeks)) < $weekly) {
                $weeks[$w] = $keep[$b['file']] = true;
            }
        }
        $deleted = [];
        foreach ($this->list() as $b) {
            if (! isset($keep[$b['file']])) {
                @unlink($this->dir().'/'.$b['file']);
                @unlink($this->dir().'/'.$b['file'].'.sha256');
                $deleted[] = $b['file'];
            }
        }

        return $deleted;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return collect(Schema::getTables())->pluck('name')->reject(fn ($t) => in_array($t, self::SKIP_TABLES, true) || str_starts_with($t, 'sqlite_'))->values()->all();
    }

    private function lastMigration(): ?string
    {
        try {
            return DB::table('migrations')->orderByDesc('id')->value('migration');
        } catch (\Throwable) {
            return null;
        }
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot open '.basename($path));
        }

        return $zip;
    }

    private function mysqldump(string $out): ?string
    {
        $c = DB::connection();
        if (! self::$useMysqldump || ! in_array($c->getDriverName(), ['mysql', 'mariadb'], true) || ! function_exists('proc_open')) {
            return null;
        }
        $bin = trim((string) @shell_exec('command -v mysqldump 2>/dev/null || command -v mariadb-dump 2>/dev/null'));
        if ($bin === '') {
            return null;
        }
        $cfg = $c->getConfig();
        $cnf = tempnam(sys_get_temp_dir(), 'my');
        file_put_contents($cnf, "[client]\nuser=\"{$cfg['username']}\"\npassword=\"".addcslashes((string) $cfg['password'], '"\\')."\"\nhost=\"{$cfg['host']}\"\nport=".($cfg['port'] ?? 3306)."\n");
        chmod($cnf, 0600);
        $ignore = implode(' ', array_map(fn ($t) => '--ignore-table='.escapeshellarg($cfg['database'].'.'.$t), self::SKIP_TABLES));
        $cmd = escapeshellcmd($bin).' --defaults-extra-file='.escapeshellarg($cnf).' --single-transaction --quick --routines --no-tablespaces '.$ignore.' '.escapeshellarg($cfg['database']).' > '.escapeshellarg($out).' 2>/dev/null';
        exec($cmd, $o, $rc);
        @unlink($cnf);

        return $rc === 0 && is_file($out) && filesize($out) > 0 ? $out : null;
    }

    private function stampOf(string $name): ?Carbon
    {
        return preg_match('/-(\d{8}-\d{6})-[a-z0-9-]+\.zip$/', $name, $m) ? Carbon::createFromFormat('Ymd-His', $m[1], 'UTC') : null;
    }

    private function triggerOf(string $name): string
    {
        return preg_match('/-\d{8}-\d{6}-([a-z0-9-]+)\.zip$/', $name, $m) ? $m[1] : 'unknown';
    }

    private static function encodeRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && ! mb_check_encoding($v, 'UTF-8')) {
                $row[$k] = ['__b64' => base64_encode($v)];
            }
        }

        return $row;
    }

    private static function decodeRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_array($v) && isset($v['__b64'])) {
                $row[$k] = base64_decode($v['__b64']);
            }
        }

        return $row;
    }
}
