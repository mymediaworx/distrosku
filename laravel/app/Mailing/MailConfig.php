<?php

namespace App\Mailing;

use App\Audit\Audit;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;

/**
 * 0.20.18: outgoing mail settings editable in Company Settings > Email.
 * Runtime rule: SMTP values saved in the app (settings table, encrypted at rest with APP_KEY via Laravel's Crypt) win when a host is saved;
 * otherwise the .env MAIL_* values apply unchanged. From address / from name fall back to .env independently.
 * The password is write-only: it is never displayed, logged, or returned to the browser.
 */
class MailConfig
{
    public const KEYS = ['mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name'];

    public const ENCRYPTIONS = ['ssl' => 'SSL / SMTPS (usually port 465)', 'tls' => 'TLS / STARTTLS (usually port 587)', 'none' => 'None (not recommended)'];

    /** @return array<string, ?string> */
    public static function stored(): array
    {
        $out = array_fill_keys(self::KEYS, null);
        try {
            foreach (Setting::query()->whereIn('key', self::KEYS)->get() as $s) {
                $v = $s->value;
                $out[$s->key] = $v === null || $v === '' ? null : (string) $v;
            }
        } catch (\Throwable) { // settings table missing (pre-migration) or a value that cannot be decrypted
        }

        return $out;
    }

    public static function hasSavedSmtp(): bool
    {
        return filled(self::stored()['mail_host']);
    }

    public static function passwordIsSet(): bool
    {
        return filled(self::stored()['mail_password']);
    }

    /** Apply saved values over the .env defaults. Safe to call repeatedly (boot, before a send). */
    public static function apply(): void
    {
        $s = self::stored();
        if (filled($s['mail_from_address'])) {
            config(['mail.from.address' => $s['mail_from_address']]);
        }
        if (filled($s['mail_from_name'])) {
            config(['mail.from.name' => $s['mail_from_name']]);
        }
        if (filled($s['mail_host'])) {
            $enc = in_array($s['mail_encryption'], array_keys(self::ENCRYPTIONS), true) ? $s['mail_encryption'] : 'tls';
            $port = (int) ($s['mail_port'] ?: ($enc === 'ssl' ? 465 : 587));
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => array_merge((array) config('mail.mailers.smtp'), [
                    'transport' => 'smtp',
                    'url' => null,
                    'host' => $s['mail_host'],
                    'port' => $port,
                    'username' => $s['mail_username'],
                    'password' => $s['mail_password'],
                    'scheme' => $enc === 'ssl' ? 'smtps' : 'smtp',
                    'require_tls' => $enc === 'tls',
                    'auto_tls' => $enc !== 'none',
                    'timeout' => 30,
                ]),
            ]);
        }
        Mail::forgetMailers();
    }

    /**
     * Save the form values. A blank password keeps the stored one; a blank host clears every saved SMTP value so the .env settings apply again.
     * Audit: the change is logged without any password (only that it was replaced).
     *
     * @param  array<string, mixed>  $d
     */
    public static function save(array $d): void
    {
        $before = self::stored();
        $host = trim((string) ($d['mail_host'] ?? ''));
        $new = ['mail_host' => $host !== '' ? $host : null];
        if ($host === '') {
            foreach (['mail_port', 'mail_encryption', 'mail_username', 'mail_password'] as $k) {
                $new[$k] = null;
            }
        } else {
            $new['mail_port'] = filled($d['mail_port'] ?? null) ? (string) (int) $d['mail_port'] : null;
            $new['mail_encryption'] = in_array($d['mail_encryption'] ?? null, array_keys(self::ENCRYPTIONS), true) ? $d['mail_encryption'] : 'tls';
            $new['mail_username'] = filled($d['mail_username'] ?? null) ? trim((string) $d['mail_username']) : null;
            if (filled($d['mail_password'] ?? null)) {
                $new['mail_password'] = (string) $d['mail_password'];
            }
        }
        $new['mail_from_address'] = filled($d['mail_from_address'] ?? null) ? trim((string) $d['mail_from_address']) : null;
        $new['mail_from_name'] = filled($d['mail_from_name'] ?? null) ? trim((string) $d['mail_from_name']) : null;

        $passwordChanged = array_key_exists('mail_password', $new) && $new['mail_password'] !== ($before['mail_password'] ?? null);
        Audit::withoutLogging(function () use ($new) { // one summary entry below instead of one per key
            foreach ($new as $k => $v) {
                Setting::put($k, $v);
            }
        });

        $show = fn (array $a) => array_diff_key($a, ['mail_password' => 1]);
        $after = self::stored();
        $oldShown = $show($before);
        $newShown = $show($after);
        if ($oldShown !== $newShown || $passwordChanged) {
            Audit::action('mail_settings', null,
                'Email settings changed'.($passwordChanged ? ' (SMTP password replaced)' : '').($host === '' ? ' (using .env mail settings)' : ''),
                $newShown, $oldShown);
        }
        self::apply();
    }

    /** Send one test message with the settings as currently saved. @return array{0: bool, 1: string} */
    public static function sendTest(string $to): array
    {
        self::apply();
        $m = (string) config('mail.default');
        if (in_array($m, ['log', 'array'], true)) {
            return [false, 'No mail server is set up yet (mailer "'.$m.'"). Save the SMTP host first, or set MAIL_* in .env.'];
        }
        try {
            Mail::raw('This is a test message from your ERP. If you can read it, outgoing email works.', function ($msg) use ($to) {
                $msg->to($to)->subject('ERP test email');
            });
        } catch (\Throwable $e) {
            return [false, self::safeError($e)];
        }

        return [true, "Test email sent to {$to}."];
    }

    /** Error text for the UI / logs with every stored credential scrubbed out. */
    public static function safeError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        $s = self::stored();
        foreach (['mail_password', 'mail_username'] as $k) {
            if (filled($s[$k])) {
                $msg = str_ireplace($s[$k], '[hidden]', $msg);
            }
        }
        foreach ([config('mail.mailers.smtp.password'), config('mail.mailers.smtp.username'), env('MAIL_PASSWORD'), env('MAIL_USERNAME')] as $v) {
            $v = (string) $v;
            if ($v !== '' && strlen($v) > 2) {
                $msg = str_ireplace($v, '[hidden]', $msg);
            }
        }

        return mb_substr(trim(preg_replace('/\s+/', ' ', $msg)), 0, 500);
    }
}
