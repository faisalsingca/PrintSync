<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailChangeRequest;
use App\Services\EmailChangeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class EmailChangeController extends Controller
{
    public function showApproval(
        EmailChangeRequest $emailChangeRequest,
        string $token,
        EmailChangeService $emailChanges,
    ): View {
        abort_unless($emailChanges->canConfirm($emailChangeRequest, $token, 'approve'), 403);

        return view('auth.email-change-confirmation', [
            'title' => __('Approve email change'),
            'message' => __('Approve changing your PrintSync email address to :email?', [
                'email' => $emailChangeRequest->new_email,
            ]),
            'confirmUrl' => route('email-change.approve', [$emailChangeRequest, $token]),
            'button' => __('Approve email change'),
        ]);
    }

    public function approve(
        EmailChangeRequest $emailChangeRequest,
        string $token,
        EmailChangeService $emailChanges,
    ): RedirectResponse {
        $completed = $emailChanges->approveOldEmail($emailChangeRequest, $token);

        return $this->confirmationResponse(
            $completed,
            __('Both confirmations are complete. Your email address has been updated.'),
            __('Your current email approved the change. Verify the new address to finish.'),
        );
    }

    public function showVerification(
        EmailChangeRequest $emailChangeRequest,
        string $token,
        EmailChangeService $emailChanges,
    ): View {
        abort_unless($emailChanges->canConfirm($emailChangeRequest, $token, 'verify'), 403);

        return view('auth.email-change-confirmation', [
            'title' => __('Verify new email address'),
            'message' => __('Verify that you own this address: :email.', [
                'email' => $emailChangeRequest->new_email,
            ]),
            'confirmUrl' => route('email-change.verify', [$emailChangeRequest, $token]),
            'button' => __('Verify email address'),
        ]);
    }

    public function verify(
        EmailChangeRequest $emailChangeRequest,
        string $token,
        EmailChangeService $emailChanges,
    ): RedirectResponse {
        $requiresOldApproval = $emailChangeRequest->old_token_hash !== null;
        $completed = $emailChanges->verifyNewEmail($emailChangeRequest, $token);

        return $this->confirmationResponse(
            $completed,
            $requiresOldApproval
                ? __('Both confirmations are complete. Your email address has been updated.')
                : __('Your new email address has been verified and updated.'),
            __('The new address is verified. Approval from your current address is still needed.'),
        );
    }

    private function confirmationResponse(
        bool $completed,
        string $completedMessage,
        string $pendingMessage,
    ): RedirectResponse {
        $message = $completed
            ? $completedMessage
            : $pendingMessage;

        return redirect()
            ->route(Auth::check() ? 'profile.edit' : 'login')
            ->with('status', $message);
    }
}
