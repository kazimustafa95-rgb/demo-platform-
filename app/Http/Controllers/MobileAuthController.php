<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\MobileAuth\EmailVerificationCodeManager;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class MobileAuthController extends Controller
{
    public function register(Request $request, CreateNewUser $creator, EmailVerificationCodeManager $verificationCodes)
    {
        $input = [
            'name' => trim((string) ($request->input('name') ?: $request->input('full_name'))),
            'email' => Str::lower(trim((string) $request->input('email'))),
            'phone_number' => trim((string) ($request->input('phone_number') ?: $request->input('phone'))),
            'password' => $request->input('password'),
            'password_confirmation' => $request->input('password_confirmation'),
        ];

        $validator = Validator::make($input, [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone_number' => 'required|string|max:25',
            'password' => ['required', 'string', PasswordRule::default(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = $creator->create($input);
        $verification = $verificationCodes->send($user);

        return response()->json([
            'message' => 'Registration successful. Verification code sent to your email.',
            'verification_required' => true,
            'verification_expires_at' => $verification['expires_at']->toISOString(),
            'resend_available_in' => $verification['resend_available_in'],
            'next_step' => 'verify_email',
            'user' => $user->fresh()->mobileProfile(),
        ], 201);
    }

    public function verifyEmailCode(Request $request, EmailVerificationCodeManager $verificationCodes)
    {
        $input = [
            'email' => Str::lower(trim((string) $request->input('email'))),
            'code' => preg_replace('/\D+/', '', (string) $request->input('code')),
            'device_name' => trim((string) $request->input('device_name')),
        ];

        $validator = Validator::make($input, [
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'device_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = User::where('email', $input['email'])->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email address is already verified.'], 422);
        }

        if (!$verificationCodes->verify($user, $input['code'])) {
            return response()->json(['message' => 'The verification code is invalid or has expired.'], 422);
        }

        $token = $user->createToken($input['device_name'])->plainTextToken;
        $user = $user->fresh();

        return response()->json([
            'message' => 'Email verified successfully.',
            'token_type' => 'Bearer',
            'token' => $token,
            'next_step' => $user->nextOnboardingStep(),
            'user' => $user->mobileProfile(),
        ]);
    }

    public function resendVerificationCode(Request $request, EmailVerificationCodeManager $verificationCodes)
    {
        $input = [
            'email' => Str::lower(trim((string) $request->input('email'))),
        ];

        $validator = Validator::make($input, [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = User::where('email', $input['email'])->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email address is already verified.'], 422);
        }

        $resendAvailableIn = $verificationCodes->resendAvailableIn($user);

        if ($resendAvailableIn > 0) {
            return response()->json([
                'message' => 'Please wait before requesting another verification code.',
                'resend_available_in' => $resendAvailableIn,
                'verification_expires_at' => $user->email_verification_code_expires_at?->toISOString(),
            ], 429);
        }

        $verification = $verificationCodes->send($user);

        return response()->json([
            'message' => 'A new verification code has been sent to your email.',
            'resend_available_in' => $verification['resend_available_in'],
            'verification_expires_at' => $verification['expires_at']->toISOString(),
        ]);
    }

    public function login(Request $request, EmailVerificationCodeManager $verificationCodes)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $email = Str::lower(trim($request->string('email')->toString()));
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($request->string('password')->toString(), $user->password)) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 401);
        }

        if (!$user->hasVerifiedEmail()) {
            $verification = null;
            $resendAvailableIn = $verificationCodes->resendAvailableIn($user);

            if ($resendAvailableIn === 0) {
                $verification = $verificationCodes->send($user);
                $resendAvailableIn = $verification['resend_available_in'];
            }

            return response()->json([
                'message' => 'Please verify your email address before logging in.',
                'verification_required' => true,
                'verification_expires_at' => ($verification['expires_at'] ?? $user->email_verification_code_expires_at)?->toISOString(),
                'resend_available_in' => $resendAvailableIn,
                'next_step' => 'verify_email',
                'user' => $user->fresh()->mobileProfile(),
            ], 403);
        }

        $token = $user->createToken($request->string('device_name')->toString())->plainTextToken;
        $user = $user->fresh();

        return response()->json([
            'message' => 'Login successful.',
            'token_type' => 'Bearer',
            'token' => $token,
            'next_step' => $user->nextOnboardingStep(),
            'user' => $user->mobileProfile(),
        ]);
    }
}
