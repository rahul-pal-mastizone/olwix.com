@extends('master.front')
@section('title', 'Redirecting to PayU')
@section('content')
    <div class="container padding-top-3x padding-bottom-3x">
        <div class="row justify-content-center">
            <div class="col-md-8 text-center">
                <h4>{{ __('Redirecting to PayU...') }}</h4>
                <p>{{ __('Please wait while we redirect you to secure payment gateway.') }}</p>

                <form id="payu_form" action="{{ $data['action'] }}" method="post">
                    <input type="hidden" name="key" value="{{ $data['key'] }}">
                    <input type="hidden" name="txnid" value="{{ $data['txnid'] }}">
                    <input type="hidden" name="amount" value="{{ $data['amount'] }}">
                    <input type="hidden" name="productinfo" value="{{ $data['productinfo'] }}">
                    <input type="hidden" name="firstname" value="{{ $data['firstname'] }}">
                    <input type="hidden" name="email" value="{{ $data['email'] }}">
                    <input type="hidden" name="phone" value="{{ $data['phone'] }}">
                    <input type="hidden" name="surl" value="{{ $data['surl'] }}">
                    <input type="hidden" name="furl" value="{{ $data['furl'] }}">
                    <input type="hidden" name="hash" value="{{ $data['hash'] }}">
                    {{-- if you want to send udf1..udf10 add them here --}}
                    <noscript>
                        <p><strong>{{ __('Please click Submit to proceed to PayU') }}</strong></p>
                        <button type="submit" class="btn btn-primary">{{ __('Submit') }}</button>
                    </noscript>
                </form>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        document.getElementById('payu_form').submit();
                    });
                </script>
            </div>
        </div>
    </div>
@endsection