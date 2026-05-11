<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PersonaIdentityVerificationService
{
    private string $provider;
    private string $apiKey;
    private string $templateId;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct()
    {
        $this->provider = strtolower(trim((string) config('services.identity_verification.provider', 'manual')));
        $this->apiKey = trim((string) config('services.identity_verification.persona_api_key'));
        $this->templateId = trim((string) config('services.identity_verification.persona_template_id'));
        $this->baseUrl = rtrim(
            (string) config('services.identity_verification.persona_base_url', 'https://api.withpersona.com/api/v1'),
            '/'
        );
        $this->timeoutSeconds = max(5, (int) config('services.identity_verification.persona_timeout_seconds', 20));
    }

    public function isEnabled(): bool
    {
        return $this->provider === 'persona';
    }

    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && $this->apiKey !== ''
            && $this->templateId !== '';
    }

    public function start(User $user): array
    {
        $this->ensureConfigured();

        $existingInquiryId = $this->extractInquiryId($user);

        if ($existingInquiryId !== null) {
            $existingInquiry = $this->safeRetrieveInquiry($existingInquiryId);

            if ($existingInquiry !== null) {
                $this->syncUserFromInquiry($user, $existingInquiry);

                $status = $this->extractStatus($existingInquiry);

                if ($this->isPassingStatus($status)) {
                    return $this->buildPayload(
                        'Persona identity verification is already complete.',
                        $user,
                        $existingInquiry,
                        null
                    );
                }

                if ($this->canResumeInquiry($status)) {
                    return $this->buildPayload(
                        'Persona identity verification is ready to continue.',
                        $user,
                        $existingInquiry,
                        $this->generateOneTimeLink($existingInquiryId)
                    );
                }
            }
        }

        $createdInquiry = $this->createInquiry($user);
        $inquiryId = $this->requireInquiryId($createdInquiry);
        $this->syncUserFromInquiry($user, $createdInquiry);

        return $this->buildPayload(
            'Persona identity verification is ready to start.',
            $user,
            $createdInquiry,
            $this->generateOneTimeLink($inquiryId)
        );
    }

    public function sync(User $user, ?string $requestedInquiryId = null): array
    {
        $this->ensureConfigured();

        $inquiryId = trim((string) ($requestedInquiryId ?: $this->extractInquiryId($user)));

        if ($inquiryId === '') {
            throw new InvalidArgumentException('No Persona inquiry has been started for this user yet.');
        }

        $inquiry = $this->retrieveInquiry($inquiryId);
        $referenceId = $this->extractReferenceId($inquiry);

        if ($referenceId !== null && $referenceId !== $this->referenceIdFor($user)) {
            throw new AccessDeniedHttpException('That Persona inquiry does not belong to the authenticated user.');
        }

        $this->syncUserFromInquiry($user, $inquiry);

        $status = $this->extractStatus($inquiry);
        $message = $this->isPassingStatus($status)
            ? 'Persona identity verification has been completed.'
            : 'Persona identity verification is not approved yet.';

        return $this->buildPayload($message, $user, $inquiry, null);
    }

    private function ensureConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Persona identity verification is not configured.');
        }
    }

    private function createInquiry(User $user): array
    {
        return $this->request()
            ->post($this->baseUrl . '/inquiries', [
                'data' => [
                    'attributes' => [
                        'inquiry-template-id' => $this->templateId,
                    ],
                ],
                'meta' => [
                    'auto-create-account-reference-id' => $this->referenceIdFor($user),
                ],
            ])
            ->throw()
            ->json();
    }

    private function generateOneTimeLink(string $inquiryId): ?string
    {
        $response = $this->request()
            ->post($this->baseUrl . '/inquiries/' . rawurlencode($inquiryId) . '/generate-one-time-link', [])
            ->throw()
            ->json();

        $link = data_get($response, 'meta.one-time-link');

        return is_string($link) && trim($link) !== '' ? trim($link) : null;
    }

    private function retrieveInquiry(string $inquiryId): array
    {
        return $this->request()
            ->get($this->baseUrl . '/inquiries/' . rawurlencode($inquiryId))
            ->throw()
            ->json();
    }

    private function safeRetrieveInquiry(string $inquiryId): ?array
    {
        try {
            return $this->retrieveInquiry($inquiryId);
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 404) {
                return null;
            }

            throw $exception;
        }
    }

    private function syncUserFromInquiry(User $user, array $inquiry): void
    {
        $inquiryId = $this->requireInquiryId($inquiry);
        $status = $this->extractStatus($inquiry);
        $now = now();
        $verifiedAt = $this->extractVerifiedAt($inquiry);

        $user->forceFill([
            'identity_verification_provider' => 'persona',
            'identity_verification_status' => $status,
            'identity_verification_reference' => $inquiryId,
            'identity_verified_at' => $this->isPassingStatus($status)
                ? ($user->identity_verified_at ?? $verifiedAt ?? $now)
                : $user->identity_verified_at,
            'identity_verification_meta' => [
                'inquiry_id' => $inquiryId,
                'reference_id' => $this->extractReferenceId($inquiry),
                'status' => $status,
                'created_at' => data_get($inquiry, 'data.attributes.created-at'),
                'started_at' => data_get($inquiry, 'data.attributes.started-at'),
                'completed_at' => data_get($inquiry, 'data.attributes.completed-at'),
                'decisioned_at' => data_get($inquiry, 'data.attributes.decisioned-at'),
                'failed_at' => data_get($inquiry, 'data.attributes.failed-at'),
                'expired_at' => data_get($inquiry, 'data.attributes.expired-at'),
                'last_synced_at' => $now->toISOString(),
            ],
            'verified_at' => $this->isPassingStatus($status)
                ? ($user->verified_at ?? $verifiedAt ?? $now)
                : $user->verified_at,
            'is_verified' => $this->isPassingStatus($status)
                ? true
                : $user->is_verified,
        ])->save();
    }

    private function buildPayload(string $message, User $user, array $inquiry, ?string $verificationUrl): array
    {
        $status = $this->extractStatus($inquiry);
        $freshUser = $user->fresh();

        return [
            'message' => $message,
            'identity_verification' => [
                'provider' => 'persona',
                'status' => $status,
                'verified' => $freshUser->hasVerifiedIdentity(),
                'verified_at' => $freshUser->identity_verified_at?->toISOString(),
                'inquiry_id' => $this->requireInquiryId($inquiry),
                'reference_id' => $this->extractReferenceId($inquiry),
                'verification_url' => $verificationUrl,
                'next_step' => $freshUser->nextOnboardingStep(),
            ],
        ];
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->withToken($this->apiKey)
            ->timeout($this->timeoutSeconds);
    }

    private function referenceIdFor(User $user): string
    {
        return 'user:' . $user->getKey();
    }

    private function extractInquiryId(User $user): ?string
    {
        $inquiryId = trim((string) $user->identity_verification_reference);

        return str_starts_with($inquiryId, 'inq_') ? $inquiryId : null;
    }

    private function requireInquiryId(array $inquiry): string
    {
        $inquiryId = trim((string) data_get($inquiry, 'data.id'));

        if (!str_starts_with($inquiryId, 'inq_')) {
            throw new RuntimeException('Persona inquiry response did not include a valid inquiry ID.');
        }

        return $inquiryId;
    }

    private function extractReferenceId(array $inquiry): ?string
    {
        $referenceId = data_get($inquiry, 'data.attributes.reference-id');

        return is_string($referenceId) && trim($referenceId) !== ''
            ? trim($referenceId)
            : null;
    }

    private function extractStatus(array $inquiry): string
    {
        $status = strtolower(trim((string) data_get($inquiry, 'data.attributes.status', '')));
        $status = preg_replace('/[\s\-]+/', '_', $status);

        return is_string($status) && $status !== '' ? $status : 'unknown';
    }

    private function isPassingStatus(string $status): bool
    {
        return in_array($status, ['approved', 'completed', 'verified', 'passed'], true);
    }

    private function canResumeInquiry(string $status): bool
    {
        return in_array($status, ['created', 'pending', 'needs_review'], true);
    }

    private function extractVerifiedAt(array $inquiry): ?Carbon
    {
        foreach ([
            data_get($inquiry, 'data.attributes.decisioned-at'),
            data_get($inquiry, 'data.attributes.completed-at'),
            data_get($inquiry, 'data.attributes.updated-at'),
        ] as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return Carbon::parse($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
