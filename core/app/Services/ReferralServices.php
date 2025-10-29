<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ReferralService
{
    /**
     * Process referral for a completed order.
     * - If order.referral_code present -> credit that code owner
     * - Else if order.user.referred_by present -> credit that user
     * Configurable amount via REFERRAL_AMOUNT env (default 200)
     */
    public function processReferralForOrder(Order $order)
    {
        // Amount to credit the referrer. Make configurable with env or settings.
        $amount = floatval(env('REFERRAL_AMOUNT', 200));

        $referrer = null;

        if ($order->referral_code) {
            $referrer = User::where('referral_code', $order->referral_code)->first();
        }

        if (!$referrer) {
            if ($order->user_id) {
                $user = User::find($order->user_id);
                if ($user && $user->referred_by) {
                    $referrer = User::find($user->referred_by);
                }
            }
        }

        if (!$referrer) {
            // Nothing to do
            return false;
        }

        DB::transaction(function () use ($referrer, $order, $amount) {
            // Ensure wallet exists
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $referrer->id],
                ['balance' => 0.00]
            );

            // Increase balance
            $wallet->balance = $wallet->balance + $amount;
            $wallet->save();

            // Log transaction
            if (class_exists('\App\Models\WalletTransaction')) {
                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'user_id'   => $referrer->id,
                    'amount'    => $amount,
                    'type'      => 'credit',
                    'source'    => 'referral',
                    'reference_id' => $order->id,
                    'note'      => 'Referral credit for order #' . $order->id,
                ]);
            }

            Log::info("Referral credited: user_id={$referrer->id} amount={$amount} order_id={$order->id}");
        });

        return true;
    }
}