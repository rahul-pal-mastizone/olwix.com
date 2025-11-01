<!-- resources/views/front/appointment/form.blade.php -->
@extends('master.front')

@section('content')
<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-md-8">
      <h3>Book Appointment - {{ ucfirst($type) }}</h3>
      <p>Fee: ₹{{ number_format($amount,2) }} (Payable now)</p>

      <form method="POST" action="{{ route('front.appointment.submit') }}">
        @csrf
        <input type="hidden" name="professional_type" value="{{ $type }}">
        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="name" class="form-control" required value="{{ auth()->check() ? auth()->user()->first_name.' '.auth()->user()->last_name : old('name') }}">
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" class="form-control" required value="{{ auth()->check() ? auth()->user()->email : old('email') }}">
        </div>
        <div class="form-group">
          <label>Phone</label>
          <input type="text" name="phone" class="form-control" required value="{{ auth()->check() ? auth()->user()->phone : old('phone') }}">
        </div>
        <div class="form-group">
          <label>Notes (optional)</label>
          <textarea name="notes" class="form-control">{{ old('notes') }}</textarea>
        </div>

        <button class="btn btn-primary">Pay & Book (₹{{ number_format($amount,2) }})</button>
      </form>
    </div>
  </div>
</div>
@endsection