<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'email',
        'password',
        'photo',
        'email_token',
        'ship_address1',
        'ship_address2',
        'ship_zip',
        'ship_city',
        'ship_country',
        'ship_company',
        'bill_address1',
        'bill_address2',
        'bill_zip',
        'bill_city',
        'bill_country',
        'bill_company',
        'state_id',
        'referral_code',
        'referred_by'
    ];


    protected $hidden = [
        'password'
    ];

    public function state()
    {
        return $this->belongsTo('App\Models\State')->withDefault();
    }

    public function products()
    {
        return $this->hasMany('App\Models\Item','vendor_id')->orderby('id','desc');
    }

    public function orders()
    {
        return $this->hasMany('App\Models\Order');
    }

    public function wishlists()
    {
        return $this->hasMany('App\Models\Wishlist');
    }

    public function reviews()
    {
        return $this->hasMany('App\Models\Review');
    }

    public function notifications()
    {
        return $this->hasMany('App\Models\Notification');
    }

    public function socialProviders()
    {
        return $this->hasMany('App\Models\SocialProvider');
    }

    public function withdraws()
    {
        return $this->hasMany('App\Models\Withdraw','vendor_id')->orderby('id','desc');
    }

    public function displayName()
    {
        return $this->first_name.' '.$this->last_name;
    }
    
    public function wishlistCount()
    {
        return $this->wishlists()->whereHas('item', function($query) {
            $query->where('status', '=', 1);
        })->count();
    }
    

    // existing code...

        /**
     * Booted: create referral_code and wallet when a user is created
     */
    protected static function booted()
    {
        static::created(function ($user) {
            // create referral code if not present
            if (!$user->referral_code) {
                $code = 'U' . strtoupper(substr(md5($user->id . time()), 0, 8));
                $user->referral_code = $code;
                $user->save();
            }

            // create wallet record if not present
            if (!$user->wallet) {
                \App\Models\Wallet::create(['user_id' => $user->id, 'balance' => 0]);
            }
        });
    }
    
    // protected static function booted()
    // {
    //     static::creating(function ($user) {
    //         // ensure referral_code is unique and present
    //         if (empty($user->referral_code)) {
    //             $user->referral_code = self::generateUniqueReferralCode();
    //         }
    //     });
    // }
    
    public static function generateUniqueReferralCode($length = 8)
    {
        do {
            $code = strtoupper('U' . substr(md5(uniqid(rand(), true)), 0, $length));
        } while (self::where('referral_code', $code)->exists());
    
        return $code;
    }
    
    // existing relationships and functions...
    // Wallet relationship
    public function wallet()
    {
        return $this->hasOne(\App\Models\Wallet::class, 'user_id');
    }

    // Referrer relationship
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by')->withDefault();
    }
}