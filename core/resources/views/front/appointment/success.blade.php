@extends('master.front')

@section('content')
<div class="container py-4">
  <div class="alert alert-success">
    <h4>Thank you!</h4>
    <p>Your appointment request for {{ ucfirst($apt->professional_type) }} is received. We will notify you when an appointment time is available. (Status: {{ $apt->payment_status }})</p>
  </div>
</div>
@endsection