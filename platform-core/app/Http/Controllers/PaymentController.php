<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Subscription;
use App\Services\MpesaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    private MpesaService $mpesa;

    // Subscription plans and pricing (KES)
    private const PLANS = [
        'basic' => ['price' => 500, 'duration_days' => 30, 'label' => 'Basic (30 days)'],
        'standard' => ['price' => 1200, 'duration_days' => 90, 'label' => 'Standard (90 days)'],
        'premium' => ['price' => 4000, 'duration_days' => 365, 'label' => 'Premium (1 year)'],
    ];

    public function __construct(MpesaService $mpesa)
    {
        $this->mpesa = $mpesa;
    }

    /**
     * GET /api/payments/plans — List available subscription plans.
     */
    public function plans(): JsonResponse
    {
        return response()->json([
            'plans' => collect(self::PLANS)->map(fn($plan, $key) => [
                'id' => $key,
                'label' => $plan['label'],
                'price_kes' => $plan['price'],
                'duration_days' => $plan['duration_days'],
            ])->values(),
        ]);
    }

    /**
     * POST /api/payments/initiate — Initiate an M-Pesa STK Push payment.
     */
    public function initiate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => ['required', 'string', 'regex:/^2547\d{8}$/'],
            'plan' => 'required|string|in:basic,standard,premium',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $plan = self::PLANS[$request->plan];
        $accountRef = "KaziBora-{$user->id}";

        try {
            $result = $this->mpesa->stkPush(
                $request->phone_number,
                $plan['price'],
                $accountRef,
                "KaziBora {$plan['label']} Subscription"
            );

            // Create pending payment record
            $payment = Payment::create([
                'user_id' => $user->id,
                'phone_number' => $request->phone_number,
                'amount' => $plan['price'],
                'merchant_request_id' => $result['MerchantRequestID'] ?? null,
                'checkout_request_id' => $result['CheckoutRequestID'] ?? null,
                'status' => 'pending',
            ]);

            // Store plan choice for callback processing
            cache()->put(
                "mpesa_plan_{$payment->checkout_request_id}",
                $request->plan,
                now()->addHours(1)
            );

            return response()->json([
                'message' => 'STK Push sent. Please complete the payment on your phone.',
                'payment_id' => $payment->id,
                'checkout_request_id' => $payment->checkout_request_id,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to initiate payment. Please try again.',
                'detail' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * POST /api/payments/mpesa/callback — M-Pesa Daraja callback endpoint (no auth).
     * Called by Safaricom servers after STK Push completion.
     */
    public function mpesaCallback(Request $request): JsonResponse
    {
        Log::info('M-Pesa callback received', $request->all());

        try {
            $data = $this->mpesa->parseCallback($request->all());

            // Find the pending payment
            $payment = Payment::where('checkout_request_id', $data['checkout_request_id'])->first();

            if (!$payment) {
                Log::warning('M-Pesa callback: payment not found', $data);
                return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
            }

            // Update payment record
            $payment->update([
                'status' => $data['result_code'] === 0 ? 'completed' : 'failed',
                'mpesa_receipt_number' => $data['mpesa_receipt_number'],
                'transaction_date' => $data['transaction_date']
                    ? \Carbon\Carbon::createFromFormat('YmdHis', (string) $data['transaction_date'])
                    : null,
                'result_code' => $data['result_code'],
                'result_description' => $data['result_description'],
            ]);

            // If payment succeeded, activate subscription
            if ($data['result_code'] === 0) {
                $planKey = cache()->pull("mpesa_plan_{$data['checkout_request_id']}") ?? 'basic';
                $plan = self::PLANS[$planKey];

                Subscription::create([
                    'user_id' => $payment->user_id,
                    'payment_id' => $payment->id,
                    'plan' => $planKey,
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => now()->addDays($plan['duration_days']),
                ]);

                Log::info("Subscription activated for user {$payment->user_id}", [
                    'plan' => $planKey,
                    'receipt' => $data['mpesa_receipt_number'],
                ]);
            }

            // Respond to Safaricom (must return quickly)
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        } catch (\Exception $e) {
            Log::error('M-Pesa callback processing failed: ' . $e->getMessage());
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }
    }

    /**
     * GET /api/payments/status/{checkoutRequestId} — Check payment status.
     */
    public function status(Request $request, string $checkoutRequestId): JsonResponse
    {
        $payment = Payment::where('checkout_request_id', $checkoutRequestId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$payment) {
            return response()->json(['error' => 'Payment not found.'], 404);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'mpesa_receipt' => $payment->mpesa_receipt_number,
            'created_at' => $payment->created_at,
        ]);
    }

    /**
     * GET /api/payments/history — Get payment history for the authenticated user.
     */
    public function history(Request $request): JsonResponse
    {
        $payments = Payment::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($payments);
    }

    /**
     * GET /api/payments/subscription — Get current subscription status.
     */
    public function subscription(Request $request): JsonResponse
    {
        $sub = $request->user()->subscription;

        if (!$sub) {
            return response()->json([
                'has_subscription' => false,
                'message' => 'No active subscription. Purchase a plan to get started.',
            ]);
        }

        return response()->json([
            'has_subscription' => true,
            'plan' => $sub->plan,
            'status' => $sub->status,
            'is_active' => $sub->isActive(),
            'starts_at' => $sub->starts_at,
            'expires_at' => $sub->expires_at,
            'days_remaining' => $sub->expires_at->isFuture()
                ? now()->diffInDays($sub->expires_at)
                : 0,
        ]);
    }
}
