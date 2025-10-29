<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        
        // existing exceptions...
        '/paytm/notify',
        '/sslcommerz/notify',
        'razorpay/notify',
        'flutterwave/notify',
        '/admin/summernote/image/upload',
        // Add PayU notify endpoint so external PayU server posts are accepted
        'payu/notify'
    ];
}
