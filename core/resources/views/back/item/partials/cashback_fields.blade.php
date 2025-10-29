<div class="form-group">
    <label for="cashback_type">Cashback type</label>
    <select name="cashback_type" id="cashback_type" class="form-control">
        <option value="">None</option>
        <option value="percent" {{ old('cashback_type', $item->cashback_type ?? '') == 'percent' ? 'selected' : '' }}>Percent (%)</option>
        <option value="fixed" {{ old('cashback_type', $item->cashback_type ?? '') == 'fixed' ? 'selected' : '' }}>Fixed amount</option>
        <option value="coins" {{ old('cashback_type', $item->cashback_type ?? '') == 'coins' ? 'selected' : '' }}>Coins (flat)</option>
    </select>
</div>

<div class="form-group">
    <label for="cashback_value">Cashback value (number)</label>
    <input type="text" name="cashback_value" id="cashback_value" class="form-control" value="{{ old('cashback_value', $item->cashback_value ?? '') }}">
    <small class="form-text text-muted">If percent chosen, enter percentage (ex: 5). If fixed/coins, enter amount per item.</small>
</div>