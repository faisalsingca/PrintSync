<?php

namespace App\Http\Controllers;

use App\Concerns\PasswordValidationRules;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StaffInvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token): View
    {
        $invitation = $this->findValidInvitation($token);

        return view('staff-invitations.accept', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token, AuditLogService $auditLog): RedirectResponse
    {
        $this->findValidInvitation($token);

        $validated = $request->validate([
            'password' => $this->passwordRules(),
        ]);

        $user = DB::transaction(function () use ($token, $validated, $auditLog): ?User {
            $invitation = StaffInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($invitation->expires_at->isPast(), 404);

            if (User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($invitation->email)])->exists()) {
                $invitation->delete();

                return null;
            }

            $user = User::create([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => $validated['password'],
                'role' => $invitation->role,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $auditLog->record(
                $invitation->createdBy,
                'admin.staff_created',
                "Created a {$user->role} account for {$user->name} after invitation confirmation.",
                User::class,
                $user->id,
                $user->name,
                ['role' => $user->role],
            );

            $invitation->delete();

            return $user;
        });

        if ($user === null) {
            return redirect()->route('login')->with('status', 'An account already exists for this email. Please sign in.');
        }

        Auth::guard(config('fortify.guard'))->login($user);
        $request->session()->regenerate();

        return redirect()->route("{$user->role}.dashboard");
    }

    private function findValidInvitation(string $token): StaffInvitation
    {
        return StaffInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->firstOrFail();
    }
}
