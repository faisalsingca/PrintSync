<?php

namespace App\Services;

use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\ApproveEmailChange;
use App\Notifications\EmailAddressChanged;
use App\Notifications\VerifyPendingEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailChangeService
{
    /**
     * Create a pending email change and send the required confirmation links.
     */
    public function requestChange(User $user, string $newEmail): bool
    {
        $this->assertEmailIsAvailable($user, $newEmail);

        $existingRequest = $user->emailChangeRequest;

        if (
            $existingRequest !== null
            && $existingRequest->new_email === $newEmail
            && $existingRequest->expires_at->isFuture()
        ) {
            return false;
        }

        $rateLimitKey = 'email-change:'.$user->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            throw ValidationException::withMessages([
                'email' => ['Too many email change requests. Please try again later.'],
            ]);
        }

        $oldToken = null;
        $newToken = Str::random(64);
        $expiresAt = now()->addHour();

        $change = DB::transaction(function () use ($user, $newEmail, $expiresAt, $newToken, &$oldToken): ?EmailChangeRequest {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $currentRequest = $lockedUser->emailChangeRequest()->lockForUpdate()->first();

            if (
                $currentRequest !== null
                && $currentRequest->new_email === $newEmail
                && $currentRequest->expires_at->isFuture()
            ) {
                return null;
            }

            if ($currentRequest !== null) {
                $currentRequest->delete();
            }

            $requiresOldApproval = $lockedUser->hasVerifiedEmail();
            $oldToken = $requiresOldApproval ? Str::random(64) : null;

            return EmailChangeRequest::query()->create([
                'user_id' => $lockedUser->id,
                'old_email' => $lockedUser->email,
                'new_email' => $newEmail,
                'old_token_hash' => $oldToken === null ? null : hash('sha256', $oldToken),
                'new_token_hash' => hash('sha256', $newToken),
                'old_approved_at' => $requiresOldApproval ? null : now(),
                'expires_at' => $expiresAt,
            ]);
        });

        if ($change === null) {
            return false;
        }

        RateLimiter::hit($rateLimitKey, 3600);

        try {
            if ($oldToken !== null) {
                Notification::route('mail', $change->old_email)->notify(new ApproveEmailChange(
                    $user->name,
                    $newEmail,
                    route('email-change.approve.show', [$change, $oldToken]),
                ));
            }

            Notification::route('mail', $newEmail)->notify(new VerifyPendingEmail(
                $user->name,
                route('email-change.verify.show', [$change, $newToken]),
            ));
        } catch (Throwable $exception) {
            $change->delete();

            throw $exception;
        }

        return true;
    }

    /**
     * Cancel any pending email change and invalidate its confirmation links.
     */
    public function cancelPending(User $user): void
    {
        $user->emailChangeRequest()->delete();
    }

    /**
     * Determine whether a confirmation link is valid and still current.
     */
    public function canConfirm(EmailChangeRequest $change, string $token, string $step): bool
    {
        $expectedHash = match ($step) {
            'approve' => $change->old_token_hash,
            'verify' => $change->new_token_hash,
            default => null,
        };
        $user = $change->user;

        return $expectedHash !== null
            && hash_equals($expectedHash, hash('sha256', $token))
            && $change->expires_at->isFuture()
            && $user !== null
            && $user->is_active
            && $user->email === $change->old_email
            && ($step !== 'approve' || $user->hasVerifiedEmail());
    }

    /**
     * Record approval from the current verified email address.
     */
    public function approveOldEmail(EmailChangeRequest $change, string $token): bool
    {
        return $this->confirm($change, $token, 'approve');
    }

    /**
     * Record verification of the proposed email address.
     */
    public function verifyNewEmail(EmailChangeRequest $change, string $token): bool
    {
        return $this->confirm($change, $token, 'verify');
    }

    /**
     * Ensure the proposed address is not already in use or pending for another account.
     */
    public function assertEmailIsAvailable(User $user, string $newEmail): void
    {
        $alreadyUsed = User::query()
            ->where('email', $newEmail)
            ->whereKeyNot($user->id)
            ->exists();

        $pendingForAnotherUser = EmailChangeRequest::query()
            ->where('new_email', $newEmail)
            ->where('user_id', '!=', $user->id)
            ->where('expires_at', '>', now())
            ->exists();

        if ($alreadyUsed || $pendingForAnotherUser) {
            throw ValidationException::withMessages([
                'email' => ['This email address is already associated with another account.'],
            ]);
        }
    }

    /**
     * Record one confirmation and apply the change once all required confirmations exist.
     *
     * @return bool Whether both confirmations were completed
     */
    private function confirm(EmailChangeRequest $change, string $token, string $step): bool
    {
        abort_unless($this->canConfirm($change, $token, $step), 403);

        $completion = DB::transaction(function () use ($change, $token, $step): ?array {
            $lockedChange = EmailChangeRequest::query()->lockForUpdate()->findOrFail($change->id);
            $user = User::query()->lockForUpdate()->findOrFail($lockedChange->user_id);

            abort_unless($this->canConfirm($lockedChange, $token, $step), 403);

            if ($step === 'approve' && $lockedChange->old_approved_at === null) {
                $lockedChange->forceFill(['old_approved_at' => now()])->save();
            }

            if ($step === 'verify' && $lockedChange->new_verified_at === null) {
                $lockedChange->forceFill(['new_verified_at' => now()])->save();
            }

            if ($lockedChange->old_approved_at === null || $lockedChange->new_verified_at === null) {
                return null;
            }

            $emailIsInUse = User::query()
                ->where('email', $lockedChange->new_email)
                ->whereKeyNot($user->id)
                ->exists();

            if ($emailIsInUse) {
                throw ValidationException::withMessages([
                    'email' => ['This email address is already associated with another account.'],
                ]);
            }

            $oldEmail = $lockedChange->old_email;
            $newEmail = $lockedChange->new_email;
            $user->forceFill([
                'email' => $newEmail,
                'email_verified_at' => now(),
            ])->save();

            $lockedChange->delete();

            return [
                'user' => $user,
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'old_email_was_verified' => $lockedChange->old_token_hash !== null,
            ];
        });

        if ($completion === null) {
            return false;
        }

        event(new Verified($completion['user']));

        if ($completion['old_email_was_verified']) {
            Notification::route('mail', $completion['old_email'])->notify(new EmailAddressChanged(
                $completion['user']->name,
                $completion['new_email'],
            ));
        }

        return true;
    }
}
