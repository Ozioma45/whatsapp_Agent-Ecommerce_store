<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use Illuminate\View\View;

class PaymentController extends Controller
{
    /**
     * List every payment transaction on the platform, newest first.
     * Deliberately platform-wide, like every other admin listing —
     * restricted entirely by the "admin" middleware on this route group.
     */
    public function index(): View
    {
        return view('admin.payments.index', [
            'payments' => PaymentTransaction::with(['business', 'plan'])->latest()->paginate(20),
        ]);
    }

    /**
     * One transaction's detail. No secret ever appears here — only the
     * fields already on the model (reference, amount, status, channel,
     * Paystack's own transaction id, timestamps).
     */
    public function show(string $payment): View
    {
        $payment = PaymentTransaction::with(['business', 'plan', 'subscription'])->findOrFail($payment);

        return view('admin.payments.show', ['payment' => $payment]);
    }
}
