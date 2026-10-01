<?php

namespace App\Services\WhatsApp\Meta;

use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\PhoneNumber;
use App\Services\WhatsApp\WhatsAppException;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Completes Cloud API onboarding. Eligibility, business verification and
 * number approval are decided by Meta's Embedded Signup flow; this service
 * only stores what Meta returns and wires up webhooks.
 */
class CloudOnboardingService
{
    public function __construct(
        private readonly GraphClient $graph,
        private readonly MetaErrorMapper $errors,
    ) {
    }

    public function isConfigured(): bool
    {
        return (bool) (config('whatsapp.meta.app_id') && config('whatsapp.meta.app_secret'));
    }

    /**
     * Embedded Signup: exchange the code returned by FB.login for a business token.
     */
    public function completeEmbeddedSignup(int $accountId, int $actorId, array $data): WhatsAppAccount
    {
        $this->assertConfigured();

        $tokenData = $this->guard(fn () => $this->graph->exchangeCode($data['code']));
        $token = $tokenData['access_token'] ?? null;
        if (! $token) {
            throw new WhatsAppException('ONBOARDING_FAILED', 'Meta did not return an access token');
        }

        return $this->connect($accountId, $actorId, $token, $data, 'embedded_signup');
    }

    /**
     * Manual: business already has a system-user token, WABA id and phone number id.
     */
    public function connectWithCredentials(int $accountId, int $actorId, array $data): WhatsAppAccount
    {
        return $this->connect($accountId, $actorId, $data['access_token'], $data, 'manual');
    }

    private function connect(int $accountId, int $actorId, string $token, array $data, string $method): WhatsAppAccount
    {
        $phoneNumberId = (string) $data['phone_number_id'];
        $wabaId = (string) $data['waba_id'];

        $existing = WhatsAppAccount::query()->where('phone_number_id', $phoneNumberId)->first();
        if ($existing && (int) $existing->user_id !== $accountId) {
            throw new WhatsAppException('PHONE_ALREADY_LINKED', 'This WhatsApp number is linked to another gateway account', 409);
        }

        $phone = $this->guard(fn () => $this->graph->getPhoneNumber($phoneNumberId, $token));
        $expiresAt = $this->tokenExpiry($token);

        if (! empty($data['pin'])) {
            $this->guard(fn () => $this->graph->registerPhoneNumber($phoneNumberId, $token, (string) $data['pin']));
        }

        $this->guard(fn () => $this->graph->subscribeApp($wabaId, $token));

        return DB::transaction(function () use ($existing, $accountId, $actorId, $token, $data, $phone, $expiresAt, $phoneNumberId, $wabaId, $method) {
            $isFirst = ! WhatsAppAccount::query()->forAccount($accountId)->whereNull('revoked_at')->exists();

            $account = $existing ?? new WhatsAppAccount(['user_id' => $accountId]);
            $account->fill([
                'connector_type' => WhatsAppAccount::CONNECTOR_CLOUD_API,
                'name' => $data['name'] ?? ($phone['verified_name'] ?? 'WhatsApp Cloud API'),
                'status' => WhatsAppAccount::STATUS_CONNECTED,
                'phone_number' => PhoneNumber::normalize($phone['display_phone_number'] ?? null),
                'display_name' => $phone['verified_name'] ?? null,
                'business_id' => $data['business_id'] ?? $account->business_id,
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
                'access_token' => $token,
                'token_expires_at' => $expiresAt,
                'connected_at' => now(),
                'disconnected_at' => null,
                'revoked_at' => null,
                'last_error' => null,
                'is_default' => $account->exists ? $account->is_default : $isFirst,
                'meta' => array_filter([
                    'onboarding' => $method,
                    'quality_rating' => $phone['quality_rating'] ?? null,
                    'name_status' => $phone['name_status'] ?? null,
                    'code_verification_status' => $phone['code_verification_status'] ?? null,
                ]),
            ])->save();

            Audit::log($actorId, 'whatsapp.cloud.connected', $account, [
                'method' => $method,
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
            ]);

            return $account;
        });
    }

    /**
     * Refresh tokens that expire soon. Non-expiring business tokens are skipped.
     */
    public function refreshToken(WhatsAppAccount $account): bool
    {
        if (! $account->hasToken()) {
            return false;
        }

        try {
            $result = $this->graph->exchangeLongLivedToken($account->access_token);
            $token = $result['access_token'] ?? null;
            if (! $token) {
                throw new MetaApiException('No token returned');
            }

            $account->update([
                'access_token' => $token,
                'token_expires_at' => isset($result['expires_in']) ? now()->addSeconds((int) $result['expires_in']) : $this->tokenExpiry($token),
                'last_error' => null,
                'status' => WhatsAppAccount::STATUS_CONNECTED,
            ]);

            Audit::log((int) $account->user_id, 'whatsapp.cloud.token_refreshed', $account);

            return true;
        } catch (MetaApiException $e) {
            $account->update([
                'status' => WhatsAppAccount::STATUS_ERROR,
                'last_error' => 'Token refresh failed; reconnect the account in the dashboard',
            ]);

            return false;
        }
    }

    private function tokenExpiry(string $token): ?Carbon
    {
        try {
            $debug = $this->graph->debugToken($token)['data'] ?? [];
        } catch (MetaApiException) {
            return null;
        }

        if (isset($debug['is_valid']) && ! $debug['is_valid']) {
            throw new WhatsAppException('TOKEN_INVALID', 'Meta reports the access token is not valid');
        }

        $expiresAt = (int) ($debug['expires_at'] ?? 0);

        return $expiresAt > 0 ? Carbon::createFromTimestamp($expiresAt) : null;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new WhatsAppException('META_NOT_CONFIGURED', 'Meta app credentials are not configured on the server', 503);
        }
    }

    private function guard(callable $fn): array
    {
        try {
            return $fn();
        } catch (MetaApiException $e) {
            $mapped = $this->errors->map($e->metaCode, $e->getMessage(), $e->httpStatus);
            throw new WhatsAppException($mapped['code'], $mapped['message'], 422, ['meta_code' => $e->metaCode]);
        }
    }
}
