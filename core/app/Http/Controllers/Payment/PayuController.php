<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Services\ReferralService;
use App\Helpers\PriceHelper;
use Illuminate\Support\Str;

class PayuController extends Controller
{
    public function store(Request $request)
    {
        // 🧹 Auto clear old appointment session when starting checkout flow
        if ($request->is('checkout/*') || $request->is('checkout') || str_contains(url()->previous(), 'checkout')) {
            Session::forget('appointment_amount');
        }

        $paymentData = PaymentSetting::where('unique_keyword', 'payu')->first();
        if (!$paymentData || $paymentData->status != 1) {
            return redirect()->back()->with('error', __('PayU payment gateway not configured or disabled.'));
        }

        $paydata = $paymentData->convertJsonData();
        $key = $paydata['merchant_key'] ?? $paydata['key'] ?? null;
        $salt = $paydata['salt'] ?? $paydata['secret'] ?? null;
        $mode = strtolower($paydata['mode'] ?? 'sandbox');

        if (empty($key) || empty($salt)) {
            Log::error('PayU config missing', ['info' => $paymentData->information]);
            return redirect()->back()->with('error', __('PayU credentials missing.'));
        }

        // ✅ Correct PayU endpoints
        $action = $mode !== 'live'
            ? 'https://secure.payu.in/_payment'
            : 'https://sandboxsecure.payu.in/_payment';

        $sanitizeAmount = function ($raw) {
            if ($raw === null) return null;
            $s = preg_replace('/[^\d\.]/', '', str_replace(',', '', (string)$raw));
            return $s === '' ? null : (float)$s;
        };

        $amount = null;
        $productinfo = '';

        /**
         * ✅ Detect appointment payment
         */
        if ($request->has('is_appointment') || Session::has('appointment_amount')) {
            $raw = Session::get('appointment_amount', 501);
            $amount = $sanitizeAmount($raw);
            $productinfo = 'Appointment Booking';
            // clear old order/cart sessions to avoid confusion
            Session::forget('order_id');
            Log::info('PayU: Appointment payment detected', ['amount' => $amount]);
        } else {
            /**
             * ✅ Detect normal checkout/cart payment
             */
            if (Session::has('order_id')) {
                $orderId = Session::get('order_id');
                $order = Order::find($orderId);
                if ($order) {
                    $candidates = [
                        $order->payable_amount,
                        $order->grand_total,
                        $order->total,
                        $order->amount,
                    ];
                    foreach ($candidates as $c) {
                        $san = $sanitizeAmount($c);
                        if ($san !== null) {
                            $amount = $san;
                            break;
                        }
                    }
                    $productinfo = 'Order Payment #' . ($order->order_number ?? $orderId);
                    Log::info('PayU: Using order amount', ['order_id' => $orderId, 'amount' => $amount]);
                }
            } else {
                // fallback to cart total
                if (Session::has('cart')) {
                    $raw = PriceHelper::cartTotal(Session::get('cart'));
                    $amount = $sanitizeAmount($raw);
                    $productinfo = 'Cart Checkout';
                    Log::info('PayU: Using cart total', ['amount' => $amount]);
                }
            }
        }

        if (empty($amount) || $amount <= 0) {
            Log::warning('PayU: Invalid payment amount', ['session' => Session::all()]);
            return redirect()->back()->with('error', __('Invalid payment amount.'));
        }

        $amount = number_format((float)$amount, 2, '.', '');
        $firstname = Auth::check() ? (Auth::user()->first_name ?? 'Customer') : ($request->input('firstname') ?? 'Customer');
        $email = Auth::check() ? (Auth::user()->email ?? 'customer@example.com') : ($request->input('email') ?? 'customer@example.com');
        $phone = Auth::check() ? (Auth::user()->phone ?? '') : ($request->input('phone') ?? '');

        $txnid = 'OLWIX' . time() . strtoupper(Str::random(6));

        $surl = route('front.payu.notify');
        $furl = route('front.checkout.cancle');

        Session::put('payu_txnid', $txnid);

        // ✅ Hash
        $hash_string = $key . '|' . $txnid . '|' . $amount . '|' . $productinfo . '|' . $firstname . '|' . $email . '|||||||||||' . $salt;
        $hash = strtolower(hash('sha512', $hash_string));

        $data = [
            'action' => $action,
            'key' => $key,
            'txnid' => $txnid,
            'amount' => $amount,
            'productinfo' => $productinfo,
            'firstname' => $firstname,
            'email' => $email,
            'phone' => $phone,
            'surl' => $surl,
            'furl' => $furl,
            'hash' => $hash,
        ];

        Log::info('PayU store prepared', ['txnid' => $txnid, 'amount' => $amount, 'productinfo' => $productinfo]);

        return view('payment.payu.submit', compact('data'));
    }

    public function notify(Request $request, ReferralService $referralService)
    {
        $posted = $request->all();
        $paymentData = PaymentSetting::where('unique_keyword', 'payu')->first();
        if (!$paymentData) {
            Log::error('PayU notify: missing payment settings');
            return redirect()->route('front.checkout.cancle')->with('error', __('Payment verification failed.'));
        }

        $paydata = $paymentData->convertJsonData();
        $salt = $paydata['salt'] ?? $paydata['secret'] ?? null;
        $key = $paydata['merchant_key'] ?? $paydata['key'] ?? null;

        $status = $posted['status'] ?? null;
        $txnid = $posted['txnid'] ?? null;
        $posted_hash = $posted['hash'] ?? null;
        $firstname = $posted['firstname'] ?? '';
        $email = $posted['email'] ?? '';
        $amount = $posted['amount'] ?? '';
        $productinfo = $posted['productinfo'] ?? '';

        if (!$posted_hash) {
            return redirect()->route('front.checkout.cancle')->with('error', __('Payment verification failed.'));
        }

        if (isset($posted['additionalCharges'])) {
            $hash_seq = $posted['additionalCharges'] . '|' . $salt . '|' . $status . '|||||||||||' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amount . '|' . $txnid . '|' . $key;
        } else {
            $hash_seq = $salt . '|' . $status . '|||||||||||' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amount . '|' . $txnid . '|' . $key;
        }

        $computed_hash = strtolower(hash('sha512', $hash_seq));

        if ($computed_hash !== strtolower($posted_hash)) {
            Log::warning('PayU notify: hash mismatch', [
                'posted' => $posted,
                'computed_hash' => $computed_hash,
                'posted_hash' => $posted_hash
            ]);
            return redirect()->route('front.checkout.cancle')->with('error', __('Payment verification failed.'));
        }

        if (strtolower($status) === 'success') {
            // 🧹 Clear appointment session on successful payment
            Session::forget('appointment_amount');

            $order = Order::where('txnid', $txnid)->orWhere('transaction_number', $txnid)->first();

            if ($order) {
                $order->payment_status = 'Completed';
                $order->payment_method = 'PayU';
                $order->order_status = 'Processing';
                $order->save();

                try {
                    $referralService->processReferralForOrder($order);
                } catch (\Exception $e) {
                    Log::error('ReferralService failed: ' . $e->getMessage(), ['order_id' => $order->id]);
                }

                return redirect()->route('front.checkout.success')->with('success', __('Payment successful.'));
            }

            // if it was an appointment
            if (Session::has('appointment_data')) {
                Session::forget('appointment_data');
                return redirect()->route('front.appointment.success')->with('success', __('Appointment booked successfully.'));
            }
        }

        return redirect()->route('front.checkout.cancle')->with('error', __('Payment failed or cancelled.'));
    }
}