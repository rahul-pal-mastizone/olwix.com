<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Exception;

class WalletService
{
    /**
     * Credit user's wallet (atomic)
     */
    public function credit($userId, $amount, $source = 'unknown', $referenceId = null, $note = null)
    {
        if ($amount <= 0) {
            throw new Exception("Invalid credit amount");
        }

        return DB::transaction(function () use ($userId, $amount, $source, $referenceId, $note) {
            $wallet = Wallet::lockForUpdate()->firstOrCreate(['user_id' => $userId], ['balance' => 0]);
            $wallet->balance = $wallet->balance + $amount;
            $wallet->save();

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $userId,
                'amount' => $amount,
                'type' => 'credit',
                'source' => $source,
                'reference_id' => $referenceId,
                'note' => $note
            ]);

            return ['wallet' => $wallet, 'tx' => $tx];
        });
    }

    /**
     * Debit user's wallet (atomic)
     */
    public function debit($userId, $amount, $source = 'order_payment', $referenceId = null, $note = null)
    {
        if ($amount <= 0) {
            throw new Exception("Invalid debit amount");
        }

        return DB::transaction(function () use ($userId, $amount, $source, $referenceId, $note) {
            $wallet = Wallet::lockForUpdate()->firstOrCreate(['user_id' => $userId], ['balance' => 0]);
            if ($wallet->balance < $amount) {
                throw new Exception("Insufficient wallet balance");
            }
            $wallet->balance = $wallet->balance - $amount;
            $wallet->save();

            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $userId,
                'amount' => $amount,
                'type' => 'debit',
                'source' => $source,
                'reference_id' => $referenceId,
                'note' => $note
            ]);

            return ['wallet' => $wallet, 'tx' => $tx];
        });
    }
}