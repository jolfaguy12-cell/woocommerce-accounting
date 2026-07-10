<?php

namespace App\Domain\Orders\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Zibal's IPG inquiry endpoint. This is the only Zibal
 * API this app has credentials for (a plain merchant code) — it answers
 * "what happened to this one transaction", not "list my deposits" (see
 * ZibalDepositImporter for why that's a manual upload instead).
 */
class ZibalGatewayClient
{
    private const INQUIRY_URL = 'https://gateway.zibal.ir/v1/inquiry';

    /**
     * Never throws on a Zibal-side rejection (e.g. invalid trackId) — callers
     * branch on 'ok' to distinguish "confirmed not successful" from "we
     * simply couldn't look it up", since only the former should alert.
     *
     * @return array{ok: bool, resultCode: ?int, status: ?string, amount: ?int, raw: array}
     */
    public function inquiry(string $trackingCode): array
    {
        $merchant = config('services.zibal.merchant');

        try {
            $response = Http::asJson()->timeout(10)->post(self::INQUIRY_URL, [
                'merchant' => $merchant,
                'trackingCode' => $trackingCode,
            ]);

            $body = $response->json() ?? [];
            $resultCode = $body['result'] ?? null;

            return [
                'ok' => $resultCode === 100 || $resultCode === 1,
                'resultCode' => $resultCode,
                'status' => isset($body['status']) ? (string) $body['status'] : ($body['message'] ?? null),
                'amount' => isset($body['amount']) ? (int) $body['amount'] : null,
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            Log::warning('Zibal inquiry failed', ['trackingCode' => $trackingCode, 'error' => $e->getMessage()]);

            return ['ok' => false, 'resultCode' => null, 'status' => null, 'amount' => null, 'raw' => []];
        }
    }
}
