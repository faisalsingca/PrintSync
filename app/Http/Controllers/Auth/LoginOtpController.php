<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\LoginOtpService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class LoginOtpController extends Controller
{
    public function show(Request $request, LoginOtpService $otp): View|RedirectResponse
    {
        $user = $otp->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return view('pages::auth.otp', [
            'maskedEmail' => $otp->maskedEmail($user),
            'showLogHint' => app()->environment('local') && config('mail.default') === 'log',
        ]);
    }

    public function verify(Request $request, LoginOtpService $otp): RedirectResponse
    {
        $user = $otp->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'digits:6']]);

        $result = $otp->verify($user, (string) $request->input('code'));

        if ($result['status'] === 'ok') {
            $remember = $otp->shouldRemember($request);
            $otp->clear($request, $user);

            Auth::guard(config('fortify.guard'))->login($user, $remember);
            $request->session()->regenerate();

            return redirect()->intended(config('fortify.home'));
        }

        if ($result['status'] === 'locked') {
            $otp->clear($request, $user);

            return redirect()->route('login')->withErrors([
                'email' => 'Too many wrong codes. Please log in again to get a new code.',
            ]);
        }

        $message = $result['status'] === 'expired'
            ? 'That code has expired. Request a new one below.'
            : "That code is not correct. {$result['attempts_left']} ".str('attempt')->plural($result['attempts_left']).' left.';

        return back()->withErrors(['code' => $message]);
    }

    public function resend(Request $request, LoginOtpService $otp): RedirectResponse
    {
        $user = $otp->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        try {
            $sent = $otp->resend($user);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['code' => 'We could not send a new code. Please try again in a moment.']);
        }

        return $sent
            ? back()->with('status', 'A new code was sent.')
            : back()->withErrors(['code' => 'Please wait a few seconds before requesting another code.']);
    }
}