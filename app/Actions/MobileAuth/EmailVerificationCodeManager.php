<?php

namespace App\Actions\MobileAuth;

use App\Models\User;
use App\Notifications\MobileEmailVerificationCodeNotification;
use Illuminate\Support\Facades\Hash;

class EmailVerificationCodeManager
{
    public function send(User $user): array
    {
        $expiresAt = now()->addMinutes($this->expiresInMinutes());
        $sentAt = now();
        $code = $this->generateCode();

        $user->forceFill([
            'email_verification_code' => Hash::make($code),
            'email_verification_code_expires_at' => $expiresAt,
            'email_verification_code_sent_at' => $sentAt,
        ])->save();

        $user->notify(new MobileEmailVerificationCodeNotification($code, $this->expiresInMinutes()));

        return [
            'expires_at' => $expiresAt,
            'resend_available_in' => $this->resendCooldownSeconds(),
        ];
    }

    public function resendAvailableIn(User $user): int
    {
        if (!$user->email_verification_code_sent_at) {
            return 0;
        }

        $availableAt = $user->email_verification_code_sent_at
            ->copy()
            ->addSeconds($this->resendCooldownSeconds());

        if (!$availableAt->isFuture()) {
            return 0;
        }

        return now()->diffInSeconds($availableAt);
    }

    public function verify(User $user, string $code): bool
    {
        if (
            blank($user->email_verification_code)
            || !$user->email_verification_code_expires_at
            || $user->email_verification_code_expires_at->isPast()
            || !Hash::check($code, $user->email_verification_code)
        ) {
            return false;
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_code_expires_at' => null,
            'email_verification_code_sent_at' => null,
        ])->save();

        return true;
    }

    private function generateCode(): string
    {
        $max = (10 ** $this->codeLength()) - 1;

        return str_pad((string) random_int(0, $max), $this->codeLength(), '0', STR_PAD_LEFT);
    }

    private function codeLength(): int
    {
        return max(4, min(8, (int) config('mobile_auth.verification_code_length', 6)));
    }

    private function expiresInMinutes(): int
    {
        return max(1, (int) config('mobile_auth.verification_code_expire_minutes', 10));
    }

    private function resendCooldownSeconds(): int
    {
        return max(0, (int) config('mobile_auth.verification_code_resend_cooldown_seconds', 60));
    }
}
