<!DOCTYPE html>
<html>
<head>
    <title>Redirecting to PayU...</title>
</head>
<body>
    <h3>Redirecting to payment gateway, please wait...</h3>
    <form id="payuForm" action="{{ route('front.payu.submit') }}" method="POST">
        @csrf
        <input type="hidden" name="appointment_id" value="{{ $appointment->id }}">
        <input type="hidden" name="amount" value="{{ $payable_amount }}">
        <input type="hidden" name="name" value="{{ $appointment->name }}">
        <input type="hidden" name="email" value="{{ $appointment->email }}">
        <input type="hidden" name="phone" value="{{ $appointment->phone }}">
        <input type="hidden" name="professional_type" value="{{ $appointment->professional_type }}">
    </form>
    <script type="text/javascript">
        document.getElementById('payuForm').submit();
    </script>
</body>
</html>
