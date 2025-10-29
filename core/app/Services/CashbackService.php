<?php

namespace App\Services;

use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;

class CashbackService
{
    protected $walletService;
    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * Process cashback and referral for an order.
     * Call this ONCE after payment success (wallet or gateway).
     */
    public function process(Order $order, $referralCode = null)
    {
        // prevent double processing
        if ($order->cashback_processed) {
            return;
        }

        DB::transaction(function () use ($order, $referralCode) {
            $user = $order->user;
            $cart = json_decode($order->cart, true) ?: [];

            $totalCashback = 0;
            foreach ($cart as $cartItem) {
                $item = Item::find($cartItem['id']);
                if (!$item) continue;

                $price = $cartItem['price'] ?? 0;
                $qty = $cartItem['qty'] ?? 1;
                $lineAmount = $price * $qty;

                $cashbackAmount = 0;
                if ($item->cashback_type && $item->cashback_value) {
                    if ($item->cashback_type == 'percent') {
                        $cashbackAmount = ($item->cashback_value / 100) * $lineAmount;
                    } elseif ($item->cashback_type == 'fixed') {
                        $cashbackAmount = $item->cashback_value * $qty;
                    } elseif ($item->cashback_type == 'coins') {
                        $cashbackAmount = $item->cashback_value * $qty;
                    }
                }

                if ($cashbackAmount > 0) {
                    $this->walletService->credit($user->id, $cashbackAmount, 'cashback', $order->id, "Cashback for item {$item->id}");
                    $totalCashback += $cashbackAmount;
                }
            }

            // referral
            if ($referralCode) {
                $referrer = User::where('referral_code', $referralCode)->first();
                if ($referrer && $referrer->id != $user->id) {
                    $refPercent = config('cashback.referral_percent', 2);
                    // determine order total
                    $orderTotal = $order->getOriginal('state_price') ?? ($order->total ?? 0);

                    if (empty($orderTotal)) {
                        $orderTotal = 0;
                        foreach ($cart as $c) {
                            $orderTotal += ($c['price'] * ($c['qty'] ?? 1));
                        }
                    }

                    $refAmount = ($refPercent / 100) * $orderTotal;
                    if ($refAmount > 0) {
                        $this->walletService->credit($referrer->id, $refAmount, 'referral', $order->id, "Referral income for order {$order->id}");
                        if (!$user->referred_by) {
                            $user->referred_by = $referrer->id;
                            $user->save();
                        }
                    }
                }
            }

            // mark order processed to avoid double-credit
            $order->cashback_processed = 1;
            $order->save();
        });
    }
}