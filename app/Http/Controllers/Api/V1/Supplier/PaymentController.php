<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Historique des encaissements d'un client (du plus récent au plus ancien).
     */
    public function index(Request $request, $customerId)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        Customer::forSupplier($supplierId)->findOrFail($customerId);

        $payments = Payment::forSupplier($supplierId)
            ->where('customer_id', $customerId)
            ->with('enteredBy:id,name')
            ->orderBy('paid_at', 'desc')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'amount' => floatval($p->amount),
                'note' => $p->note,
                'paid_at' => $p->paid_at?->toISOString(),
                'entered_by' => $p->enteredBy?->name,
            ]);

        return response()->json(['payments' => $payments]);
    }

    /**
     * Enregistrer un encaissement reçu d'un client.
     */
    public function store(Request $request, $customerId)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'amount' => 'required|numeric|gt:0|max:99999999.99',
            'note' => 'nullable|string|max:255',
        ]);

        Customer::forSupplier($supplierId)->findOrFail($customerId);

        $payment = Payment::create([
            'supplier_id' => $supplierId,
            'customer_id' => $customerId,
            'amount' => $request->amount,
            'note' => $request->note,
            'paid_at' => now(),
            'entered_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Encaissement enregistré.',
            'payment' => [
                'id' => $payment->id,
                'amount' => floatval($payment->amount),
                'paid_at' => $payment->paid_at?->toISOString(),
            ],
        ], 201);
    }
}
