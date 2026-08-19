<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    /**
     * UyumSoft ve yerel sipariş faturalarını listele.
     */
    public function index(Request $request): View
    {
        $query = ShopifyOrder::query()
            ->with(['shipments.cargoCompany'])
            ->withInvoice()
            ->latest('uyumsoft_pushed_at')
            ->latest();

        $source = $request->string('source')->toString();
        if ($source === 'uyumsoft') {
            $query->where(function ($builder): void {
                $builder->whereNotNull('uyumsoft_einvoice_uuid')
                    ->orWhereNotNull('uyumsoft_invoice_id')
                    ->orWhereNotNull('uyumsoft_invoice_no');
            });
        } elseif ($source === 'local') {
            $query->whereNotNull('invoice_path');
        }

        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('uyumsoft_invoice_no', 'like', "%{$search}%")
                    ->orWhere('uyumsoft_einvoice_uuid', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('shopify_created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('shopify_created_at', '<=', $request->date('date_to'));
        }

        return view('invoices.index', [
            'orders' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['q', 'source', 'date_from', 'date_to']),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Faturalar'],
            ],
        ]);
    }
}
