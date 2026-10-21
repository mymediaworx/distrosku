<?php

namespace App\Audit;

use App\Models\ActivityLog;
use App\Models\PosPinLogin;
use App\Models\Setting;
use App\Models\User;
use App\Pos\PosPin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Phase 16 change history. Model events (created/updated/deleted) for every App\Models model are recorded by
 * AuditServiceProvider; key business actions and auth events call Audit::action(). Secrets are masked.
 */
class Audit
{
    public const MASK = '********';

    /** Models never logged (the log itself, high-volume technical rows). */
    public const SKIP = [ActivityLog::class, PosPinLogin::class];

    /** Columns never logged on any model (noise). */
    public const IGNORE = ['updated_at', 'created_at', 'remember_token'];

    private static bool $paused = false;

    public static function isSecret(string $key): bool
    {
        return (bool) preg_match('/pass(word)?|secret|token|api_?key|transaction_key|client_key|_hash$|(^|_)pin(_|$)|_lookup$|signature_key|private/i', $key);
    }

    /** @return array<string, mixed> */
    public static function clean(array $values, ?Model $m = null): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            if (in_array($k, self::IGNORE, true)) {
                continue;
            }
            if (self::isSecret($k) || ($m instanceof Setting && $k === 'value' && self::isSecret((string) $m->getAttribute('key')))) {
                $out[$k] = $v === null ? null : self::MASK;

                continue;
            }
            if ($v instanceof \BackedEnum) {
                $v = $v->value;
            } elseif ($v instanceof \DateTimeInterface) {
                $v = $v->format('Y-m-d H:i:s');
            } elseif (is_string($v) && strlen($v) > 2000) {
                $v = substr($v, 0, 2000).'…';
            }
            $out[$k] = $v;
        }

        return $out;
    }

    public static function label(Model $m): string
    {
        foreach (['number', 'code', 'sku', 'name', 'email', 'key', 'title'] as $a) {
            if (filled($v = $m->getAttribute($a)) && is_scalar($v)) {
                return mb_substr((string) $v, 0, 190);
            }
        }

        return class_basename($m).' #'.$m->getKey();
    }

    public static function model(string $event, Model $m, array $old = [], array $new = []): void
    {
        if (self::$paused || in_array($m::class, self::SKIP, true)) {
            return;
        }
        self::write($event, $m, self::clean($old, $m), self::clean($new, $m));
    }

    public static function action(string $event, ?Model $subject = null, ?string $description = null, array $new = [], array $old = [], ?int $userId = null): void
    {
        self::write($event, $subject, self::clean($old, $subject), self::clean($new, $subject), $description, $userId);
    }

    /** @var list<array<string, mixed>> durable entries written inside a still-open transaction */
    private static array $pending = [];

    /**
     * 0.18.1: a log entry that must survive a rollback (e.g. a blocked POS / van sale whose order is rolled back).
     * It is written at once; if a later rollback erases it, it is written again right after that rollback (subject kept
     * only if it still exists). Entries are forgotten when the outermost transaction commits.
     */
    public static function durable(string $event, ?Model $subject = null, ?string $description = null, array $new = []): void
    {
        $level = \Illuminate\Support\Facades\DB::transactionLevel();
        $id = self::write($event, $subject, [], self::clean($new, $subject), $description);
        if ($level > 0) {
            self::$pending[] = ['level' => $level, 'log_id' => $id, 'event' => $event, 'class' => $subject ? $subject::class : null, 'id' => $subject?->getKey(),
                'label' => $subject ? self::label($subject) : null, 'desc' => $description, 'new' => $new, 'user' => Auth::id()];
        }
    }

    /** After a rollback to $level: re-write durable entries from deeper levels that the rollback erased. */
    public static function afterRollback(int $level): void
    {
        $keep = [];
        foreach (self::$pending as $p) {
            if ($p['level'] <= $level) {
                $keep[] = $p;

                continue;
            }
            if ($p['log_id'] && ActivityLog::whereKey($p['log_id'])->exists()) {
                continue;
            }
            $m = $p['class'] && $p['id'] ? $p['class']::query()->find($p['id']) : null;
            $new = $p['new'] + ($m || ! $p['class'] ? [] : ['rolled_back' => true, 'subject' => $p['label']]);
            self::write($p['event'], $m, [], self::clean($new, $m), $p['desc'], $p['user']);
        }
        self::$pending = $keep;
    }

    public static function resetPending(): void
    {
        self::$pending = [];
    }

    /** Run without model-event logging (e.g. bulk technical jobs). */
    public static function withoutLogging(callable $fn): mixed
    {
        $was = self::$paused;
        self::$paused = true;
        try {
            return $fn();
        } finally {
            self::$paused = $was;
        }
    }

    private static function write(string $event, ?Model $m, array $old, array $new, ?string $desc = null, ?int $userId = null): ?int
    {
        try {
            $u = Auth::user() ?? (($pid = session()->isStarted() ? session(PosPin::SESSION_USER) : null) ? User::find($pid) : null);
            if ($userId && (! $u || $u->getKey() !== $userId)) {
                $u = User::find($userId) ?? $u;
            }
            $r = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            return ActivityLog::create([
                'event' => mb_substr($event, 0, 40),
                'subject_type' => $m ? $m::class : null, 'subject_id' => $m?->getKey(), 'subject_label' => $m ? self::label($m) : null,
                'user_id' => $u?->getKey() ?? $userId, 'user_name' => $u?->name ? mb_substr($u->name, 0, 120) : null,
                'ip' => $r?->ip(), 'user_agent' => $r ? mb_substr((string) $r->userAgent(), 0, 255) : null,
                'old_values' => $old ?: null, 'new_values' => $new ?: null, 'description' => $desc ? mb_substr($desc, 0, 255) : null,
            ])->getKey();
        } catch (Throwable $e) {
            report($e); // never break a business transaction because logging failed

            return null;
        }
    }
}
