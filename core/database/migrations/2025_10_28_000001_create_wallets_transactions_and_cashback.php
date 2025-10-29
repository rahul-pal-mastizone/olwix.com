<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateWalletsTransactionsAndCashback extends Migration
{
    public function up()
    {
        // Wallets
        if (!Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->index()->unique();
                $table->decimal('balance', 16, 2)->default(0);
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Wallet transactions ledger
        if (!Schema::hasTable('wallet_transactions')) {
            Schema::create('wallet_transactions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('wallet_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->decimal('amount', 16, 2);
                $table->enum('type', ['credit', 'debit']);
                $table->string('source')->nullable(); // cashback|referral|order_payment|refund
                $table->unsignedBigInteger('reference_id')->nullable(); // order_id or item_id
                $table->text('note')->nullable();
                $table->timestamps();

                $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('cascade');
            });
        }

        // Items: cashback
        if (!Schema::hasColumn('items', 'cashback_type')) {
            Schema::table('items', function (Blueprint $table) {
                $table->string('cashback_type')->nullable()->after('affiliate_link'); // percent|fixed|coins
                $table->decimal('cashback_value', 16, 2)->nullable()->after('cashback_type'); // meaning depends on type
            });
        }

        // Users: referral_code, referred_by
        if (!Schema::hasColumn('users', 'referral_code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('referral_code')->nullable()->unique()->after('email');
                $table->unsignedBigInteger('referred_by')->nullable()->after('referral_code');
            });
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('referred_by')->references('id')->on('users')->onDelete('set null');
            });
        }

        // Orders: referral_code, used_wallet_amount, cashback_processed
        if (!Schema::hasColumn('orders', 'referral_code')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('referral_code')->nullable()->after('payment_method');
                $table->decimal('used_wallet_amount', 16, 2)->default(0)->after('referral_code');
                $table->tinyInteger('cashback_processed')->default(0)->after('used_wallet_amount'); // 0 => not yet, 1 => processed
            });
        }
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cashback_processed')) {
                $table->dropColumn('cashback_processed');
            }
            if (Schema::hasColumn('orders', 'used_wallet_amount')) {
                $table->dropColumn('used_wallet_amount');
            }
            if (Schema::hasColumn('orders', 'referral_code')) {
                $table->dropColumn('referral_code');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referred_by')) {
                $table->dropForeign(['referred_by']);
                $table->dropColumn('referred_by');
            }
            if (Schema::hasColumn('users', 'referral_code')) {
                $table->dropColumn('referral_code');
            }
        });

        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'cashback_type')) {
                $table->dropColumn('cashback_type');
            }
            if (Schema::hasColumn('items', 'cashback_value')) {
                $table->dropColumn('cashback_value');
            }
        });

        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
}