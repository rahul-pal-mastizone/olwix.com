<?php

namespace App\Repositories\Front;

use App\{
    Models\User,
    Models\Setting,
    Helpers\EmailHelper,
    Models\Notification
};
use App\Helpers\ImageHelper;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class UserRepository
{

    // public function register($request){
    //     $input = $request->all();

    //     $user = new User;
    //     $input['password'] = bcrypt($request['password']);
    //     $input['email'] = $input['email'];
    //     $input['first_name'] = $input['first_name'];
    //     $input['last_name'] = $input['last_name'];
    //     $input['phone'] = $input['phone'];
    //     $verify = Str::random(6);
    //     $input['email_token'] = $verify;
    //     $user->fill($input)->save();

    //     Notification::create(['user_id' => $user->id]);

    //     // If a referral_code was provided, attach referrer (do not allow self-referral)
    //     $referralCode = $request->input('referral_code');
    //     if ($referralCode) {
    //         $referrer = User::where('referral_code', $referralCode)->first();
    //         if ($referrer && $referrer->id != $user->id) {
    //             $user->referred_by = $referrer->id;
    //             $user->save();
    //         }
    //     }


    //     $emailData = [
    //         'to' => $user->email,
    //         'type' => "Registration",
    //         'user_name' => $user->displayName(),
    //         'order_cost' => '',
    //         'transaction_number' => '',
    //         'site_title' => Setting::first()->title,
    //     ];

    //     $email = new EmailHelper();
    //     $email->sendTemplateMail($emailData);

    // }


        /**
     * Register a new user (accept optional referral code).
     * Returns created User instance.
     */
    public function register($request)
    {
        $input = $request->all();

        try {
            // Normalize and prepare input
            $password = bcrypt($request->input('password'));
            $input['password'] = $password;
            $input['email'] = $input['email'] ?? null;
            $input['first_name'] = $input['first_name'] ?? null;
            $input['last_name'] = $input['last_name'] ?? null;
            $input['phone'] = $input['phone'] ?? null;

            // Keep the referral code the user provided separate so we do NOT
            // accidentally assign it as the new user's referral_code (which
            // is unique in DB). We'll use it only to find the referrer.
            $providedReferral = $request->input('referral_code', null);
            if (isset($input['referral_code'])) {
                unset($input['referral_code']);
            }

            // create email token (existing behavior)
            $verify = Str::random(6);
            $input['email_token'] = $verify;

            // ensure this new user receives a unique referral_code
            // generate one and guarantee uniqueness
            do {
                $newReferralCode = 'U' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
            } while (User::where('referral_code', $newReferralCode)->exists());

            $input['referral_code'] = $newReferralCode;

            // create user
            $user = new User;
            $user->fill($input);
            $user->save();

            // create Notification entry as before
            Notification::create(['user_id' => $user->id]);

            // If a referral_code was provided, attach referrer (do not allow self-referral)
            if ($providedReferral) {
                $referrer = User::where('referral_code', $providedReferral)->first();
                if ($referrer && $referrer->id != $user->id) {
                    $user->referred_by = $referrer->id;
                    $user->save();
                }
            }

            // send registration email (existing logic)
            $emailData = [
                'to' => $user->email,
                'type' => "Registration",
                'user_name' => method_exists($user, 'displayName') ? $user->displayName() : ($user->first_name . ' ' . $user->last_name),
                'order_cost' => '',
                'transaction_number' => '',
                'site_title' => Setting::first()->title ?? config('app.name'),
            ];

            $email = new EmailHelper();
            // wrap send to avoid breaking registration if mail fails
            try {
                $email->sendTemplateMail($emailData);
            } catch (\Throwable $e) {
                Log::warning('Failed to send registration email: ' . $e->getMessage(), ['user_id' => $user->id]);
            }

            return $user;

        } catch (\Throwable $e) {
            // Log the error with sufficient context for debugging
            Log::error('UserRepository::register failed: ' . $e->getMessage(), [
                'input_keys' => array_keys($request->all()),
                'exception' => $e,
            ]);

            // Re-throw so controller or exception handler can return proper response
            throw $e;
        }
    }




    public function profileUpdate($request){
        $input = $request->all();
        if($request['user_id']){
            $user = User::findOrFail($request['user_id']);
        }else{
            $user = Auth::user();
        }


        if($request->password){
            $input['password'] = bcrypt($input['password']);
            $user->password = $input['password'];
            $user->update();
        }else{
            unset($input['password']);
        }

      
        if ($file = $request->file('photo')) {
            $input['photo'] = ImageHelper::handleUpdatedUploadedImage($file,'/assets/images',$user,'/assets/images/','photo');
        }

        if($request->newsletter){
            if(!Subscriber::where('email',$user->email)->exists()){
                Subscriber::insert([
                    'email' => $user->email
                ]);
            }
        }else{
            Subscriber::where('email',$user->email)->delete();
        }

        $user->fill($input)->save();
    }




}
