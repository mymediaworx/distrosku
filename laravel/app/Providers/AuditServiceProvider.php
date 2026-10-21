<?php

namespace App\Providers;

use App\Audit\Audit;
use App\Models\Company;
use App\Models\DeliverySignature;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\PeriodAudit;
use App\Models\PriceOverrideLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Phase 16: wires every App\Models model + auth events into the activity log. */
class AuditServiceProvider extends ServiceProvider
{
    /** Creating one of these IS the key action. */
    public const CREATE_ACTIONS = [
        Journal::class => 'posted',
        DeliverySignature::class => 'signed',
        PriceOverrideLog::class => 'price_override',
        Setting::class => 'settings_changed',
        Payment::class => 'payment',
        PeriodAudit::class => 'period_change',
    ];

    public function boot(): void
    {
        // 0.18.1: durable log entries (blocked POS / van sales) are re-written after a rollback that erased them.
        Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, fn ($e) => \App\Audit\Audit::afterRollback($e->connection->transactionLevel()));
        Event::listen(\Illuminate\Database\Events\TransactionCommitted::class, function ($e) {
            if ($e->connection->transactionLevel() === 0) {
                \App\Audit\Audit::resetPending();
            }
        });
        foreach (['created', 'updated', 'deleted'] as $ev) {
            Event::listen("eloquent.$ev: *", function (string $name, array $data) use ($ev) {
                $m = $data[0] ?? null;
                if (! $m instanceof Model || ! str_starts_with($m::class, 'App\\Models\\')) {
                    return;
                }
                if ($ev === 'created') {
                    Audit::model('created', $m, [], $m->getAttributes());
                    if ($key = self::CREATE_ACTIONS[$m::class] ?? null) {
                        Audit::action($key, $m, class_basename($m).' '.Audit::label($m));
                    }
                } elseif ($ev === 'deleted') {
                    Audit::model('deleted', $m, $m->getRawOriginal() ?: $m->getAttributes());
                } else {
                    $changes = array_diff_key($m->getChanges(), array_flip(Audit::IGNORE));
                    if (! $changes) {
                        return;
                    }
                    $old = array_intersect_key($m->getRawOriginal(), $changes);
                    Audit::model('updated', $m, $old, $changes);
                    // Key actions (post, void, deliver, invoice, approve, file, close ...) are status transitions.
                    if (array_key_exists('status', $changes) && ! in_array($m::class, Audit::SKIP, true)) {
                        $to = (string) ($changes['status'] instanceof \BackedEnum ? $changes['status']->value : $changes['status']);
                        Audit::action($to, $m, class_basename($m).' '.Audit::label($m).': '.($old['status'] ?? '?').' → '.$to);
                    }
                    if ($m instanceof Setting || $m instanceof Company) {
                        Audit::action('settings_changed', $m, 'Settings changed: '.implode(', ', array_keys($changes)));
                    }
                    if ($m instanceof User) {
                        if (array_key_exists('role', $changes)) {
                            Audit::action('role_changed', $m, "Role {$old['role']} → {$changes['role']}", ['role' => $changes['role']], ['role' => $old['role'] ?? null]);
                        }
                        if (array_key_exists('password', $changes)) {
                            Audit::action('password_changed', $m, 'Password changed');
                        }
                    }
                }
            });
        }
        Event::listen(Login::class, fn (Login $e) => Audit::action('login', $e->user instanceof Model ? $e->user : null, "Signed in ({$e->guard})", userId: $e->user?->getAuthIdentifier()));
        Event::listen(Logout::class, fn (Logout $e) => $e->user ? Audit::action('logout', $e->user instanceof Model ? $e->user : null, "Signed out ({$e->guard})", userId: $e->user->getAuthIdentifier()) : null);
        Event::listen(Failed::class, fn (Failed $e) => Audit::action('login_failed', null, 'Failed sign-in for '.mb_substr((string) ($e->credentials['email'] ?? '?'), 0, 120)." ({$e->guard})"));
        Event::listen(PasswordReset::class, fn (PasswordReset $e) => Audit::action('password_reset', $e->user instanceof Model ? $e->user : null, 'Password reset via emailed link', userId: $e->user->getAuthIdentifier()));
    }
}
