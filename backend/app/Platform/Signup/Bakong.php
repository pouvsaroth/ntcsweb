<?php

declare(strict_types=1);

namespace App\Platform\Signup;

use App\Platform\Models\BillingSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bakong Open API (api-bakong.nbc.gov.kh): has a KHQR code been paid?
 * Looked up by the MD5 of the exact code string (docs/backoffice/stage-8-signup.md, 8c).
 * The token is NewTech's, kept encrypted in back office → Billing → Settings.
 */
final class Bakong
{
    public function configured(): bool
    {
        $settings = BillingSettings::current();

        return filled($settings->khqr_template) && filled($settings->bakong_token);
    }

    /**
     * The payment for this code, or null while nobody has paid it.
     *
     * @return array{hash: string, amount: float, currency: string, from: ?string, to: ?string}|null
     *
     * @throws RuntimeException when Bakong cannot be asked (no token, token refused, Bakong down)
     */
    public function paymentFor(string $md5): ?array
    {
        $token = BillingSettings::current()->bakong_token;
        if (blank($token)) {
            throw new RuntimeException('No Bakong token is set (back office → Billing → Settings).');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(10)
            ->post(rtrim((string) config('platform.bakong.api_url'), '/').'/v1/check_transaction_by_md5', ['md5' => $md5]);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Bakong refused the token — it may have expired. Renew it in back office → Billing → Settings.');
        }
        if (! $response->successful()) {
            throw new RuntimeException("Bakong did not answer (HTTP {$response->status()}).");
        }

        // responseCode 0 = found and paid; anything else = not (yet) paid.
        $data = $response->json('data');
        if ((int) $response->json('responseCode', 1) !== 0 || ! is_array($data) || empty($data['hash'])) {
            return null;
        }

        return [
            'hash' => (string) $data['hash'],
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'from' => isset($data['fromAccountId']) ? (string) $data['fromAccountId'] : null,
            'to' => isset($data['toAccountId']) ? (string) $data['toAccountId'] : null,
        ];
    }

    /**
     * "Test the token" in Billing → Settings: asks Bakong about a code nobody
     * paid. A working token gets "not found"; Bakong's own message is shown.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array
    {
        try {
            $token = BillingSettings::current()->bakong_token;
            if (blank($token)) {
                return ['ok' => false, 'message' => __('No Bakong token is set.')];
            }
            $response = Http::withToken($token)->acceptJson()->timeout(10)
                ->post(rtrim((string) config('platform.bakong.api_url'), '/').'/v1/check_transaction_by_md5', ['md5' => md5('ntcsweb-token-test-'.now()->timestamp)]);
            $message = (string) ($response->json('responseMessage') ?? $response->reason());

            return ['ok' => $response->successful(), 'message' => "HTTP {$response->status()} · {$message}"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** When a Bakong token (a JWT) stops working, read from the token itself; null if it does not say. */
    public static function expiryOf(string $token): ?\Illuminate\Support\Carbon
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($payload) && isset($payload['exp']) && is_numeric($payload['exp'])
            ? \Illuminate\Support\Carbon::createFromTimestamp((int) $payload['exp'])
            : null;
    }
}
