<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class LoginOtpService
{
    private const SESSION_USER = 'otp.user_id';

    private const SESSION_REMEMBER = 'otp.remember';

    /**
     * Create a code, email it, and remember who is waiting to verify.
     */
    public function start(Request $request, User $user): void
    {
        $this->sendCode($user);

        $request->session()->put([
            self::SESSION_USER => $user->id,
            self::SESSION_REMEMBER => $request->boolean('remember'),
        ]);
    }

    public function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::SESSION_USER);

        return $id ? User::query()->find($id) : null;
    }

    public function shouldRemember(Request $request): bool
    {
        return (bool) $request->session()->get(self::SESSION_REMEMBER, false);
    }

    /**
     * Send a fresh code. Returns false while the resend cooldown is still running.
     */
    public function resend(User $user): bool
    {
        if (Cache::has($this->cooldownKey($user))) {
            return false;
        }

        $this->sendCode($user);

        return true;
    }

    /**
     * Check a code the user typed.
     *
     * @return array{status: string, attempts_left: int} status is ok, invalid, expired, or locked
     */
    public function verify(User $user, string $code): array
    {
        $record = Cache::get($this->codeKey($user));
        $max = (int) config('otp.max_attempts');

        if ($record === null) {
            return ['status' => 'expired', 'attempts_left' => 0];
        }

        if (hash_equals($record['hash'], $this->hash($code))) {
            Cache::forget($this->codeKey($user));
            Cache::forget($this->cooldownKey($user));

            return ['status' => 'ok', 'attempts_left' => $max];
        }

        $record['attempts']++;

        if ($record['attempts'] >= $max) {
            Cache::forget($this->codeKey($user));

            return ['status' => 'locked', 'attempts_left' => 0];
        }

        Cache::put($this->codeKey($user), $record, Carbon::createFromTimestamp($record['expires_at']));

        return ['status' => 'invalid', 'attempts_left' => $max - $record['attempts']];
    }

    public function clear(Request $request, User $user): void
    {
        Cache::forget($this->codeKey($user));
        Cache::forget($this->cooldownKey($user));
        $request->session()->forget([self::SESSION_USER, self::SESSION_REMEMBER]);
    }

    public function maskedEmail(User $user): string
    {
        [$local, $domain] = array_pad(explode('@', $user->email, 2), 2, '');

        return substr($local, 0, 2).str_repeat('*', max(strlen($local) - 2, 1)).'@'.$domain;
    }

    private function sendCode(User $user): void
    {
        $minutes = (int) config('otp.expires_minutes');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes($minutes);

        Cache::put($this->codeKey($user), [
            'hash' => $this->hash($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        Cache::put($this->cooldownKey($user), true, now()->addSeconds((int) config('otp.resend_seconds')));

        Mail::raw(
            "Your PrintSync login code is {$code}.\n\nIt expires in {$minutes} minutes. If you did not try to log in, ignore this email and consider changing your password.",
            function ($message) use ($user): void {
                $message->to($user->email)->subject('Your PrintSync login code');
            },
        );
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function codeKey(User $user): string
    {
        return "login-otp:{$user->id}";
    }

    private function cooldownKey(User $user): string
    {
        return "login-otp-cooldown:{$user->id}";
    }
}