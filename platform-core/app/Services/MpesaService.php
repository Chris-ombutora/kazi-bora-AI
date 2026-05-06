<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Safaricom Daraja (M-Pesa) API integration.
 * Handles STK Push initiation, callback processing, and transaction queries.
 */
class MpesaService
{
    private array $config;
    private Client $client;

    public function __construct()
    {
        $this->config = config('services.mpesa');
        $this->client = new Client([
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /**
     * Get the base URLs for the current environment (sandbox/production).
     */
    private function getUrls(): array
    {
        $env = $this->config['env'];
        return $this->config['urls'][$env];
    }

    /**
     * Generate an OAuth access token from Daraja API.
     */
    public function getAccessToken(): string
    {
        try {
            $response = $this->client->get($this->getUrls()['oauth'], [
                'auth' => [
                    $this->config['consumer_key'],
                    $this->config['consumer_secret'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['access_token'];
        } catch (GuzzleException $e) {
            Log::error('M-Pesa OAuth failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to authenticate with M-Pesa API.', 503, $e);
        }
    }

    /**
     * Initiate an STK Push (Lipa Na M-Pesa Online) payment request.
     *
     * @param string $phoneNumber Phone number in format 2547XXXXXXXX
     * @param float  $amount      Amount in KES
     * @param string $accountRef  Account reference (e.g., "KaziBora-Sub-123")
     * @param string $description Transaction description
     * @return array Daraja API response containing MerchantRequestID, CheckoutRequestID
     */
    public function stkPush(string $phoneNumber, float $amount, string $accountRef, string $description = 'KaziBora Subscription'): array
    {
        $accessToken = $this->getAccessToken();
        $timestamp = now()->format('YmdHis');
        $password = base64_encode(
            $this->config['shortcode'] . $this->config['passkey'] . $timestamp
        );

        try {
            $response = $this->client->post($this->getUrls()['stk_push'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'json' => [
                    'BusinessShortCode' => $this->config['shortcode'],
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => (int) $amount,
                    'PartyA' => $phoneNumber,
                    'PartyB' => $this->config['shortcode'],
                    'PhoneNumber' => $phoneNumber,
                    'CallBackURL' => $this->config['callback_url'],
                    'AccountReference' => $accountRef,
                    'TransactionDesc' => $description,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('M-Pesa STK Push initiated', [
                'merchant_request_id' => $data['MerchantRequestID'] ?? null,
                'checkout_request_id' => $data['CheckoutRequestID'] ?? null,
                'phone' => substr($phoneNumber, 0, 6) . '****',
            ]);

            return $data;

        } catch (GuzzleException $e) {
            Log::error('M-Pesa STK Push failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to initiate M-Pesa payment.', 503, $e);
        }
    }

    /**
     * Query the status of an STK Push transaction.
     */
    public function querySTKStatus(string $checkoutRequestId): array
    {
        $accessToken = $this->getAccessToken();
        $timestamp = now()->format('YmdHis');
        $password = base64_encode(
            $this->config['shortcode'] . $this->config['passkey'] . $timestamp
        );

        try {
            $response = $this->client->post($this->getUrls()['stk_query'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
                'json' => [
                    'BusinessShortCode' => $this->config['shortcode'],
                    'Password' => $password,
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $checkoutRequestId,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            Log::error("M-Pesa STK query failed for {$checkoutRequestId}: {$e->getMessage()}");
            throw new \RuntimeException('Failed to query M-Pesa transaction status.', 503, $e);
        }
    }

    /**
     * Parse and validate an M-Pesa callback payload.
     *
     * @param array $callbackData Raw callback JSON from Daraja
     * @return array Normalized payment data
     */
    public function parseCallback(array $callbackData): array
    {
        $stkCallback = $callbackData['Body']['stkCallback'] ?? null;

        if (!$stkCallback) {
            throw new \InvalidArgumentException('Invalid M-Pesa callback structure.');
        }

        $result = [
            'merchant_request_id' => $stkCallback['MerchantRequestID'],
            'checkout_request_id' => $stkCallback['CheckoutRequestID'],
            'result_code' => (int) $stkCallback['ResultCode'],
            'result_description' => $stkCallback['ResultDesc'],
            'amount' => null,
            'mpesa_receipt_number' => null,
            'transaction_date' => null,
            'phone_number' => null,
        ];

        // ResultCode 0 = success, extract metadata items
        if ($result['result_code'] === 0 && isset($stkCallback['CallbackMetadata'])) {
            foreach ($stkCallback['CallbackMetadata']['Item'] as $item) {
                match ($item['Name']) {
                    'Amount' => $result['amount'] = $item['Value'],
                    'MpesaReceiptNumber' => $result['mpesa_receipt_number'] = $item['Value'],
                    'TransactionDate' => $result['transaction_date'] = $item['Value'],
                    'PhoneNumber' => $result['phone_number'] = (string) $item['Value'],
                    default => null,
                };
            }
        }

        return $result;
    }
}
