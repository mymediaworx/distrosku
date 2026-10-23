<?php

namespace App\Dba;

use App\Models\Setting;

/** 0.20.8: link + suspend state set by the instance manager. Suspension changes no business data. */
class InstanceState
{
    public static function secret(): string
    {
        return (string) (Setting::get('dba_secret') ?: config('erp.dba.secret') ?: '');
    }

    public static function suspended(): bool
    {
        return Setting::get('dba_suspended') === '1';
    }

    public static function suspend(?string $reason, ?string $by): void
    {
        Setting::put('dba_suspended', '1');
        Setting::put('dba_suspended_reason', $reason ? mb_substr($reason, 0, 500) : null);
        Setting::put('dba_suspended_at', now()->toIso8601String());
        self::log('suspended', $by, ['reason' => $reason]);
    }

    public static function resume(?string $by): void
    {
        Setting::put('dba_suspended', '0');
        Setting::put('dba_suspended_reason', null);
        Setting::put('dba_resumed_at', now()->toIso8601String());
        self::log('resumed', $by);
    }

    public static function log(string $event, ?string $by, array $extra = []): void
    {
        try {
            \App\Models\ActivityLog::create(['event' => 'dba_'.$event, 'subject_type' => 'instance', 'subject_label' => 'Instance manager',
                'user_name' => $by ? mb_substr('DBA: '.$by, 0, 120) : 'DBA', 'ip' => request()?->ip(),
                'new_values' => $extra ?: null, 'description' => 'Instance manager: '.$event]);
        } catch (\Throwable) {
            logger()->info('dba.'.$event, ['by' => $by] + $extra);
        }
    }
}
