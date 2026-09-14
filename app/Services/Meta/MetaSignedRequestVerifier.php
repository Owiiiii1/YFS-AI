<?php

namespace App\Services\Meta;

class MetaSignedRequestVerifier
{
    public function parse(string $signedRequest, string $appSecret): array
    {
        $signedRequest = trim($signedRequest);
        $appSecret = trim($appSecret);

        if ($signedRequest === '' || $appSecret === '' || ! str_contains($signedRequest, '.')) {
            throw new InvalidMetaSignedRequestException(
                InvalidMetaSignedRequestException::REASON_MALFORMED,
                400,
            );
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);

        if ($encodedSignature === '' || $encodedPayload === '') {
            throw new InvalidMetaSignedRequestException(
                InvalidMetaSignedRequestException::REASON_MALFORMED,
                400,
            );
        }

        $signature = $this->base64UrlDecode($encodedSignature);
        if ($signature === null) {
            throw new InvalidMetaSignedRequestException(
                InvalidMetaSignedRequestException::REASON_MALFORMED,
                400,
            );
        }

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $appSecret, true);
        if (! hash_equals($expectedSignature, $signature)) {
            throw new InvalidMetaSignedRequestException(
                InvalidMetaSignedRequestException::REASON_INVALID_SIGNATURE,
                403,
            );
        }

        $payloadJson = $this->base64UrlDecode($encodedPayload);
        if ($payloadJson === null) {
            throw new InvalidMetaSignedRequestException(
                InvalidMetaSignedRequestException::REASON_MALFORMED,
                400,
            );
        }

        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            throw new InvalidMetaSignedRequestException(
                InvalidMetaSignedRequestException::REASON_MALFORMED,
                400,
            );
        }

        $algorithm = strtoupper((string) ($payload['algorithm'] ?? 'HMAC-SHA256'));
        if ($algorithm !== 'HMAC-SHA256') {
            throw new InvalidMetaSignedRequestException(
                InvalidMetaSignedRequestException::REASON_INVALID_SIGNATURE,
                403,
            );
        }

        return $payload;
    }

    public function platformUserId(array $payload): ?string
    {
        $candidates = [
            $payload['user_id'] ?? null,
            data_get($payload, 'user.id'),
            $payload['instagram_user_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
