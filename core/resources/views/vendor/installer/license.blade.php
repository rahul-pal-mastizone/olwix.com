@extends('vendor.installer.layouts.master')

@section('template_title')
    {{ trans('installer_messages.license.templateTitle') }}
@endsection

@section('title')
    <i class="fa fa-key fa-fw" aria-hidden="true"></i>
    License Verification
@endsection

@section('container')
    <div class="tabs tabs-full">
        @if(session()->has('domin_url'))
            <div class="alert alert-success" style="background-color: #d4edda;" id="license_alert">
                <strong>This Purchase Code Already Use for other Domin :</strong>
                @foreach (session()->get('domin_url') as $item)
                    <p style="margin-bottom: 0px;color: #155724;">{{ $item }}</p>
                @endforeach
                <strong>
                    Envato not allow to install multiple domin. One purched codes for one Domin.
                    Author can take action any time for that.
                    <br>
                    Author Support : <a href="https://support.geniusdevs.com/" target="_blank">support.geniusdevs.com</a>
                </strong>
            </div> 
        @endif
        <form method="post" action="{{ route('LaravelInstaller::licenseCheck') }}" class="tabs-wrap">
            @if(session()->has('license_error'))
                <div class="alert alert-danger" id="error_alert">
                    <button type="button" class="close" id="close_alert" data-dismiss="alert" aria-hidden="true">
                        <i class="fa fa-close" aria-hidden="true"></i>
                    </button>
                    <p style="margin-bottom: 0px;">{{session()->get('license_error')}}</p>
                </div>
            @endif

            <div class="alert alert-warning" style="background-color: #fff3cd; color: #856404;">
                <p style="margin-bottom: 0px;">If your internet connection is off, then please turn it on first</p>
            </div>

            <input type="hidden" name="_token" value="{{ csrf_token() }}">

            <div>
                <div class="form-group {{ $errors->has('email') ? ' has-error ' : '' }}">
                    <label for="email">
                        Email Address
                    </label>
                    <input type="text" name="email" id="email" value="{{ old('email') }}" placeholder="Your Mail Address" />
                    <p>This Mail Address will be used to inform you about Urgent Notices, Announcements, Offers / Sales etc...</p>
                    @if ($errors->has('email'))
                        <span class="error-block">
                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                            {{ $errors->first('email') }}
                        </span>
                    @endif
                </div>

                <div class="form-group {{ $errors->has('username') ? ' has-error ' : '' }}">
                    <label for="username">
                        Envato Username
                    </label>
                    <input type="text" name="username" id="username" value="{{ old('username') }}" placeholder="Username of Your Envato Account" />
                    @if ($errors->has('username'))
                        <span class="error-block">
                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                            {{ $errors->first('username') }}
                        </span>
                    @endif
                </div>

                <div class="form-group {{ $errors->has('purchase_code') ? ' has-error ' : '' }}">
                    <label for="purchase_code">
                        Purchase Code
                    </label>
                    <input type="text" name="purchase_code" id="purchase_code" value="{{ old('purchase_code') }}" placeholder="Your Item Purchase Code" />
                    @if ($errors->has('purchase_code'))
                        <span class="error-block">
                            <i class="fa fa-fw fa-exclamation-triangle" aria-hidden="true"></i>
                            {{ $errors->first('purchase_code') }}
                        </span>
                    @endif
                </div>

                <div class="buttons">
                    <button class="button" type="submit" style="font-size: 14px;">
                        Verify
                        <i class="fa fa-angle-right fa-fw" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

        </form>

    </div>
@endsection

