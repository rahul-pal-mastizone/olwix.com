@php $user = Auth::user(); @endphp

<div class="form-group">
    <label for="referral_code">Referral code (optional)</label>
    <input type="text" name="referral_code" id="referral_code" class="form-control" value="{{ old('referral_code') }}">
    <small class="form-text text-muted">If you have a referral code, enter it here (optional).</small>
</div>

@if($user && $user->wallet)
    <div class="form-group">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="use_wallet" name="use_wallet">
            <label class="form-check-label" for="use_wallet">
                Pay full order with Wallet (Balance: {{ number_format($user->wallet->balance ?? 0, 2) }})
            </label>
        </div>
    </div>
@endif