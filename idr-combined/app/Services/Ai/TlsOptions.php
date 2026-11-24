<?php

namespace App\Services\Ai;

/**
 * Guzzle options for outgoing HTTPS calls (Gemini, Qdrant): points certificate
 * verification at the bundled CA list so it works even when PHP has none
 * configured. Verification is never turned off.
 */
final class TlsOptions
{
    /** @return array{verify?: string} */
    public static function forHttpClient(): array
    {
        $bundle = config('services.ca_bundle');

        if (! is_string($bundle) || $bundle === '' || strtolower($bundle) === 'system') {
            return [];
        }

        if (! is_file($bundle)) {
            throw new \RuntimeException("CA bundle not found at [{$bundle}]. Fix IDR_CA_BUNDLE in .env, or set it to \"system\".");
        }

        return ['verify' => $bundle];
    }
}
