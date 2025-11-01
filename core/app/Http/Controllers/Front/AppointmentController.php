<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Appointment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AppointmentController extends Controller
{
    protected $validTypes = ['pandit', 'lawyer', 'pediatrician', 'lady_doctor'];

    public function showForm($type)
    {
        $type = strtolower($type);
        if (!in_array($type, $this->validTypes)) {
            abort(404);
        }

        return view('front.appointment.form', [
            'type'   => $type,
            'amount' => 501.00,
        ]);
    }

public function submit(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255',
        'phone' => 'required|string|max:50',
        'professional_type' => 'required|string'
    ]);

    $type = strtolower($request->professional_type);
    if (!in_array($type, $this->validTypes)) {
        return redirect()->back()->with('error', 'Invalid professional type.');
    }

    $userId = Auth::check() ? Auth::id() : null;

    $apt = Appointment::create([
        'user_id' => $userId,
        'name' => $request->name,
        'email' => $request->email,
        'phone' => $request->phone,
        'professional_type' => $type,
        'notes' => $request->notes ?? null,
        'amount' => 501.00,
        'payment_status' => 'pending', // important: keep pending
        'status' => 'new',
    ]);

    // Put appointment id and amount in session for PayU
    Session::put('appointment_id', $apt->id);
    Session::put('payable_amount', $apt->amount);

    // Redirect to PayU auto-submit page
    return view('front.appointment.payu_redirect', [
        'appointment' => $apt,
        'payable_amount' => $apt->amount,
    ]);
}


    public function success($id)
    {
        $apt = Appointment::findOrFail($id);
        return view('front.appointment.success', compact('apt'));
    }
}
