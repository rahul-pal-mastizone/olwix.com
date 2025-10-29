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
        // Validate minimal inputs (your checkout validation may be richer)
        $request->validate([]);

        $paymentData = PaymentSetting::where('unique_keyword', 'payu')->first();
        if (!$paymentData || $paymentData->status != 1) {
            return redirect()->back()->with('error', __('PayU payment gateway not configured or disabled.'));
        }

        $paydata = $paymentData->convertJsonData();

        // Accept a variety of field names from admin panel
        $key = $paydata['merchant_key'] ?? $paydata['key'] ?? $paydata['client_id'] ?? null;
        // support 'secret' (your DB has 'secret'), 'salt' and 'client_secret'
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

        $action = $mode === 'live' ? 'https://secure.payu.in/_payment' : 'https://sandboxsecure.payu.in/_payment';

        // Total amount calculation — use your existing price helper (adjust if needed)
        $amount = PriceHelper::cartTotal(Session::get('cart'));
        $amount = number_format((float)$amount, 2, '.', '');

        $firstname = Auth::check() ? (Auth::user()->first_name ?: 'Customer') : ($request->input('firstname') ?: 'Customer');
        $email = Auth::check() ? (Auth::user()->email ?: 'customer@example.com') : ($request->input('email') ?: 'customer@example.com');
        $phone = Auth::check() ? (Auth::user()->phone ?: '') : ($request->input('phone') ?: '');

        $txnid = 'OLWIX' . time() . Str::upper(Str::random(6));
        $productinfo = 'Order Payment';

        $surl = route('front.payu.notify'); // success notify url (PayU will POST here)
        $furl = route('front.checkout.cancle');

        Session::put('payu_txnid', $txnid);

        // Hash for PayU (key|txnid|amount|productinfo|firstname|email|||||||||||salt)
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

        return view('payment.payu.submit', compact('data'));
    }

    /**
     * PayU notify/response handler
     * Verifies hash and on success triggers referral/wallet processing
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

        // Recompute hash the PayU way
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

        // Payment verified
        if (strtolower($status) === 'success') {
            // Find order by txnid or transaction_number whichever you store earlier
            $order = Order::where('txnid', $txnid)->orWhere('transaction_number', $txnid)->first();

            // If your order was created earlier with a different txnid storage, adjust lookup accordingly.
            if ($order) {
                $order->payment_status = 'Completed';
                $order->payment_method = 'PayU';
                $order->order_status = 'Processing';
                $order->save();

                // Process referral/cashback/wallet credit
                try {
                    $referralService->processReferralForOrder($order);
                } catch (\Exception $e) {
                    Log::error('ReferralService failed: ' . $e->getMessage(), ['order_id' => $order->id]);
                }

                // redirect to success page in your app
                return redirect()->route('front.checkout.success')->with('success', __('Payment successful.'));
            }

            // If order not found, you might want to log and/or create a transaction record
            Log::warning('PayU notify: payment success but order not found', ['posted' => $posted]);
            return redirect()->route('front.checkout.cancle')->with('error', __('Payment processed but order not found.'));
        }

        return redirect()->route('front.checkout.cancle')->with('error', __('Payment failed or cancelled.'));
    }
}