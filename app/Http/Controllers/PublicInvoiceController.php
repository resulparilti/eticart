<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicInvoiceController extends Controller
{
    /**
     * Download an invoice by unguessable token.
     */
    public function show(string $token): BinaryFileResponse
    {
        $order = ShopifyOrder::query()
            ->where('invoice_token', $token)
            ->whereNotNull('invoice_path')
            ->firstOrFail();

        if (! Storage::disk('public')->exists((string) $order->invoice_path)) {
            abort(404);
        }

        $absolute = Storage::disk('public')->path((string) $order->invoice_path);
        $name = $order->invoice_original_name ?: 'fatura';

        return response()->download($absolute, $name);
    }
}
