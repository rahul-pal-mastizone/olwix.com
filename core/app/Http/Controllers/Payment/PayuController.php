<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PaymentSetting;
use Illuminate\Support\Facades\Session;
use App\Helpers\PriceHelper;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Services\ReferralService;

class PayuController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([]);

        $paymentData = PaymentSetting::where('unique_keyword', 'payu')->first();
        if (!$paymentData || $paymentData->status != 1) {
            return redirect()->back()->with('error', __('PayU payment gateway not configured or disabled.'));
        }

        $paydata = $paymentData->convertJsonData();

        $key = $paydata['merchant_key'] ?? $paydata['key'] ?? $paydata['client_id'] ?? null;
        $salt = $paydata['salt'] ?? $paydata['client_secret'] ?? $paydata['secret'] ?? null;
        $mode = strtolower($paydata['mode'] ?? ($paydata['environment'] ?? 'sandbox'));

        if (empty($key) || empty($salt)) {
            Log::error('PayU config missing or empty', [
                'payment_setting_id' => $paymentData->id,
                'information' => $paymentData->information,
                'parsed' => $paydata
            ]);
            return redirect()->back()->with('error', __('PayU credentials are not configured properly. Please verify merchant key and salt in Payment Settings.'));
        }

        // Use live endpoint when mode is 'live', otherwise sandbox
        $action = $mode !== 'live' ? 'https://secure.payu.in/_payment' : 'https://sandboxsecure.payu.in/_payment';

        // Local helper to sanitize amount strings (remove commas, currency symbols, etc.)
        $sanitizeAmount = function ($raw) {
            if ($raw === null) return null;
            // Convert to string, remove common thousands separators and non-numeric except dot
            $s = (string)$raw;
            // Replace commas (thousands separator used by PriceHelper) and any non-digit/dot characters
            $s = str_replace(',', '', $s);
            $s = preg_replace('/[^\d\.]/', '', $s);
            // Remove multiple dots if any (keep first)
            if (substr_count($s, '.') > 1) {
                $parts = explode('.', $s);
                $s = array_shift($parts) . '.' . implode('', $parts);
            }
            if ($s === '') return null;
            return (float)$s;
        };

        // Determine amount: check session keys, check Order in session, fallback to PriceHelper
        $amount = null;

        // Common session keys that other parts of checkout may set
        $possibleSessionKeys = [
            'payable_amount', 'grand_total', 'total_amount', 'order_amount', 'order_total', 'payment_amount', 'amount'
        ];

        foreach ($possibleSessionKeys as $k) {
            if (Session::has($k)) {
                $raw = Session::get($k);
                $san = $sanitizeAmount($raw);
                if ($san !== null) {
                    $amount = $san;
                    Log::info('PayU Payment: Amount taken from session key', ['key' => $k, 'raw' => $raw, 'sanitized' => $san]);
                    break;
                }
            }
        }

        // If not found, try an existing order in session
        if (empty($amount) && Session::has('order_id')) {
            try {
                $orderId = Session::get('order_id');
                $order = Order::find($orderId);
                if ($order) {
                    // try common numeric fields on order; pick first non-null
                    $candidates = [
                        $order->payable_amount ?? null,
                        $order->total ?? null,
                        $order->grand_total ?? null,
                        $order->amount ?? null,
                    ];
                    foreach ($candidates as $c) {
                        $san = $sanitizeAmount($c);
                        if ($san !== null) {
                            $amount = $san;
                            Log::info('PayU Payment: Amount taken from existing order', ['order_id' => $orderId, 'raw' => $c, 'sanitized' => $san]);
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('PayU store: failed to load order from session: ' . $e->getMessage(), ['order_id' => Session::get('order_id')]);
            }
        }

        // Fallback to cart total
        if (empty($amount)) {
            $raw = PriceHelper::cartTotal(Session::get('cart'));
            Log::info('PayU Amount from PriceHelper', ['amount_raw' => $raw]);
            $san = $sanitizeAmount($raw);
            if ($san !== null) {
                $amount = $san;
            }
        }

        // Final sanity check and format for PayU
        if ($amount === null || !is_numeric($amount) || $amount <= 0) {
            Log::warning('PayU store: computed payment amount is invalid', [
                'amount_final' => $amount,
                'session_keys' => array_intersect_key(Session::all(), array_flip($possibleSessionKeys)),
                'cart' => Session::get('cart'),
            ]);
            return redirect()->back()->with('error', __('Invalid payment amount.'));
        }

        // Format with two decimals
        $amount = number_format((float)$amount, 2, '.', '');

        $firstname = Auth::check() ? (Auth::user()->first_name ?: 'Customer') : ($request->input('firstname') ?: 'Customer');
        $email = Auth::check() ? (Auth::user()->email ?: 'customer@example.com') : ($request->input('email') ?: 'customer@example.com');
        $phone = Auth::check() ? (Auth::user()->phone ?: '') : ($request->input('phone') ?: '');

        $txnid = 'OLWIX' . time() . Str::upper(Str::random(6));
        $productinfo = 'Order Payment';

        $surl = route('front.payu.notify');
        $furl = route('front.checkout.cancle');

        Session::put('payu_txnid', $txnid);

        // store() : after Session::put('payu_txnid', $txnid);
if (Session::has('appointment_id')) {
    try {
        $aptId = Session::get('appointment_id');
        $apt = \App\Models\Appointment::find($aptId);
        if ($apt) {
            $apt->txnid = $txnid;
            $apt->save();
            Log::info('PayU store: linked txnid to appointment', ['appointment_id'=>$aptId, 'txnid'=>$txnid]);
        }
    } catch (\Throwable $e) {
        Log::warning('PayU store: failed to link appointment txnid: '.$e->getMessage());
    }
}

// If order not found, try appointment
$appointment = \App\Models\Appointment::where('txnid', $txnid)->first();
if ($appointment) {
    $appointment->payment_status = 'paid';
    $appointment->status = 'notified'; // or keep 'new' and handle notifications separately
    $appointment->save();

    // Optionally send email or notification to admin/pandit - you can dispatch a Job here.

    Log::info('PayU notify: appointment payment marked paid', ['appointment_id'=>$appointment->id]);
    // Redirect to appointment success page (shows thank you)
    return redirect()->route('front.appointment.success', $appointment->id)->with('success', __('Payment successful. Appointment booked.'));
}

        // Hash for PayU
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

        Log::info('PayU store prepared', [
            'txnid' => $txnid,
            'amount' => $amount,
            'amount_raw_logged' => isset($raw) ? $raw : null,
            'action' => $action
        ]);

        return view('payment.payu.submit', compact('data'));
    }

    /**
     * PayU notify/response handler
     */
    public function notify(Request $request, ReferralService $referralService)
    {
        $posted = $request->all();
        $paymentData = PaymentSetting::where('unique_keyword', 'payu')->first();
        if (!$paymentData) {
            Log::error('PayU notify: missing payment settings');
            return redirect()->route('front.checkout.cancle')->with('error', __('Payment verification failed.'));
        }

        $paydata = $paymentData->convertJsonData();
        $salt = $paydata['salt'] ?? $paydata['client_secret'] ?? $paydata['secret'] ?? null;
        $key = $paydata['merchant_key'] ?? $paydata['key'] ?? $paydata['client_id'] ?? null;

        if (empty($salt) || empty($key)) {
            Log::error('PayU notify: credentials missing', ['parsed' => $paydata]);
            return redirect()->route('front.checkout.cancle')->with('error', __('Payment verification failed.'));
        }

        $status = $posted['status'] ?? null;
        $txnid = $posted['txnid'] ?? null;
        $posted_hash = $posted['hash'] ?? null;
        $firstname = $posted['firstname'] ?? '';
        $email = $posted['email'] ?? '';
        $amount = $posted['amount'] ?? '';
        $productinfo = $posted['productinfo'] ?? '';

        if (!$posted_hash) {
            Log::warning('PayU notify: no hash in response', ['posted' => $posted]);
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

            Log::warning('PayU notify: payment success but order not found', ['posted' => $posted]);
            return redirect()->route('front.checkout.cancle')->with('error', __('Payment processed but order not found.'));
        }

        return redirect()->route('front.checkout.cancle')->with('error', __('Payment failed or cancelled.'));
    }
}