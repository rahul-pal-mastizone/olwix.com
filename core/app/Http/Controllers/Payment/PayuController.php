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

/**
 * PayU Controller
 *
 * - Reads DB payment_settings row with unique_keyword = 'payu'
 * - Accepts multiple common key names (merchant_key, key, client_id) and salt names (salt, client_secret)
 * - Computes PayU hash and renders an auto-submit form to PayU
 * - Verifies PayU response hash in notify()
 */
class PayuController extends Controller
{
    /**
     * Prepare and submit to PayU.
     */
    public function store(Request $request)
    {
        // Validate required checkout fields (mirror other gateways)
        $request->validate([
            'state_id' => '' // adjust according to your store rules
        ]);

        $paymentData = PaymentSetting::where('unique_keyword', 'payu')->first();
        if (!$paymentData || $paymentData->status != 1) {
            return redirect()->back()->with('error', __('PayU payment gateway not configured or disabled.'));
        }

        $paydata = $paymentData->convertJsonData();
        // Defensive read of keys (support different admin field names)
        $key = $paydata['merchant_key'] ?? $paydata['key'] ?? $paydata['client_id'] ?? null;
        $salt = $paydata['salt'] ?? $paydata['client_secret'] ?? $paydata['salt_key'] ?? null;
        $mode = strtolower($paydata['mode'] ?? ($paydata['environment'] ?? 'sandbox'));

        if (empty($key) || empty($salt)) {
            // Log the actual paydata for debugging (do not expose in UI)
            Log::error('PayU config missing or empty', [
                'payment_setting_id' => $paymentData->id,
                'information' => $paymentData->information,
                'parsed' => $paydata
            ]);
            // Provide a helpful UI message
            return redirect()->back()->with('error', __('PayU credentials are not configured properly. Please verify merchant key and salt in Payment Settings.'));
        }

        $action = $mode === 'live' ? 'https://secure.payu.in/_payment' : 'https://sandboxsecure.payu.in/_payment';

        // Amount from cart helper
        $amount = PriceHelper::cartTotal(Session::get('cart'));
        $amount = number_format((float)$amount, 2, '.', '');

        // User details
        $firstname = Auth::check() ? (Auth::user()->first_name ?: 'Customer') : ($request->input('firstname') ?: 'Customer');
        $email = Auth::check() ? (Auth::user()->email ?: 'customer@example.com') : ($request->input('email') ?: 'customer@example.com');
        $phone = Auth::check() ? (Auth::user()->phone ?: '') : ($request->input('phone') ?: '');

        // Transaction id
        $txnid = 'OLWIX' . time() . Str::upper(Str::random(6));

        $productinfo = 'Order Payment';

        // success & failure URLs
        $surl = route('front.payu.notify');
        $furl = route('front.checkout.cancle');

        // Save txn id in session for debugging/verification if needed
        Session::put('payu_txnid', $txnid);

        // Compute hash according to PayU docs:
        // hashString = key|txnid|amount|productinfo|firstname|email|||||||||||salt
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
     * Verifies hash and redirects to success or cancel page.
     * Important: Extend this to finalize order (create order entry / send emails) as per your existing checkout flow.
     */
    public function notify(Request $request)
    {
        $posted = $request->all();
        $paymentData = PaymentSetting::where('unique_keyword', 'payu')->first();
        if (!$paymentData) {
            Log::error('PayU notify: missing payment settings');
            return redirect()->route('front.checkout.cancle')->with('error', __('Payment verification failed.'));
        }

        $paydata = $paymentData->convertJsonData();
        $salt = $paydata['salt'] ?? $paydata['client_secret'] ?? $paydata['salt_key'] ?? null;
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

        // Recompute hash
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
            // TODO: Finalize order here: create Order record or mark existing order paid (use your CheckoutController logic).
            Session::put('payu_response', $posted);
            return redirect()->route('front.checkout.success')->with('success', __('Payment successful.'));
        }

        return redirect()->route('front.checkout.cancle')->with('error', __('Payment failed or cancelled.'));
    }
}