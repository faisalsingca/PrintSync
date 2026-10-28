<?php

namespace App\Actions\Fortify;

use App\Services\LoginOtpService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Throwable;

class SendLoginOtp
{
    public function __construct(
        private readonly LoginRateLimiter $limiter,
        private readonly LoginOtpService $otp,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $provider = Auth::createUserProvider(config('auth.guards.'.config('fortify.guard').'.provider'));
        $credentials = $request->only(Fortify::username(), 'password');
        $user = $provider->retrieveByCredentials($credentials);

        if ($user === null || ! $user->is_active || ! $provider->validateCredentials($user, $credentials)) {
            $this->limiter->increment($request);

            throw ValidationException::withMessages([
                Fortify::username() => [trans('auth.failed')],
            ]);
        }

        try {
            $this->otp->start($request, $user);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                Fortify::username() => ['We could not send your login code. Please try again in a moment.'],
            ]);
        }

        $this->limiter->clear($request);

        return redirect()->route('otp.show');
    }
}
