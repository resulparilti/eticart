<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use App\Services\UyumSoftEInvoiceService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PublicInvoiceController extends Controller
{
    public function __construct(
        private readonly UyumSoftEInvoiceService $eInvoiceService
    ) {
    }

    /**
     * Download an invoice by unguessable token.
     *
     * Portal faturaları diskte tutulmaz; UyumSoft’tan anlık çekilir.
     */
    public function show(string $token): BinaryFileResponse|Response
    {
        $order = ShopifyOrder::query()
            ->where('invoice_token', $token)
            ->where(function ($query): void {
                $query->whereNotNull('invoice_path')
                    ->orWhereNotNull('uyumsoft_einvoice_uuid');
            })
            ->firstOrFail();

        if ($order->hasLocalInvoiceFile()) {
            $absolute = Storage::disk('public')->path((string) $order->invoice_path);
            $name = $order->invoice_original_name ?: $order->invoiceAttachmentName();

            return response()->download($absolute, $name);
        }

        $uuid = trim((string) $order->uyumsoft_einvoice_uuid);
        if ($uuid === '') {
            abort(404);
        }

        try {
            $document = Cache::remember(
                'einvoice-pdf:'.$uuid,
                now()->addMinutes(30),
                fn (): array => $this->eInvoiceService->downloadOfficialDocument($uuid)
            );
        } catch (Throwable) {
            abort(404);
        }

        $name = $order->invoiceAttachmentName();

        return response($document['content'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
