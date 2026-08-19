<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MailTemplate;
use App\Models\Notification;
use App\Models\ShopifyOrder;
use App\Models\SmsTemplate;
use App\Support\OrderMessageTemplates;
use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(
        private readonly MailService $mailService,
        private readonly SmsService $smsService
    ) {
    }

    /**
     * List outbound notifications.
     */
    public function index(Request $request): View
    {
        $query = Notification::query()->latest();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($builder) use ($q) {
                $builder->where('recipient', 'like', "%{$q}%")
                    ->orWhere('subject', 'like', "%{$q}%")
                    ->orWhere('body', 'like', "%{$q}%");
            });
        }

        return view('notifications.index', [
            'notifications' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['type', 'status', 'date_from', 'date_to', 'q']),
            'smsBalance' => $this->smsService->getBalance(),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Mesaj bilgilendirmeleri'],
            ],
        ]);
    }

    /**
     * Resend a notification.
     */
    public function resend(Request $request, Notification $notification): RedirectResponse
    {
        $redirect = redirect()->route('notifications.index');

        if ($request->isMethod('get')) {
            return $redirect->with('error', 'Mail göndermek için listedeki “Yeniden Gönder” butonunu kullanın.');
        }

        try {
            if ($notification->type === 'mail') {
                $result = $this->mailService->sendCustom(
                    (string) $notification->recipient,
                    (string) ($notification->subject ?: 'Bildirim'),
                    (string) $notification->body,
                    $this->relatedModel($notification)
                );

                $message = method_exists($result, 'reportMessage')
                    ? $result->reportMessage()
                    : (string) ($result->error ?: 'Mail işlemi tamamlandı.');

                if ($result->status === 'failed') {
                    return $redirect->with('error', $message !== '' ? $message : 'Mail yeniden gönderilemedi.');
                }

                return $redirect->with('success', $message !== '' ? $message : 'Bildirim yeniden gönderildi.');
            }

            $this->smsService->send((string) $notification->recipient, (string) $notification->body);
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
            }

            return $redirect->with('error', 'Yeniden gönderilemedi: '.$e->getMessage());
        }

        return $redirect->with('success', 'Bildirim yeniden gönderildi.');
    }

    private function relatedModel(Notification $notification): mixed
    {
        try {
            $notification->loadMissing('notifiable');
            if ($notification->notifiable) {
                return $notification->notifiable;
            }
        } catch (Throwable) {
        }

        $id = (int) ($notification->notifiable_id ?? 0);
        $type = (string) ($notification->notifiable_type ?? '');
        if ($id < 1) {
            return null;
        }

        if ($type === ShopifyOrder::class || str_ends_with($type, '\\ShopifyOrder')) {
            return ShopifyOrder::query()->find($id);
        }

        return null;
    }

    public function destroy(Notification $notification): RedirectResponse
    {
        $notification->delete();

        return redirect()
            ->route('notifications.index')
            ->with('success', 'Bilgilendirme kaydı silindi.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $count = Notification::query()
            ->whereIn('id', $validated['ids'])
            ->delete();

        return redirect()
            ->route('notifications.index')
            ->with('success', "{$count} bilgilendirme kaydı silindi.");
    }

    /**
     * Template management page.
     */
    public function templates(): View
    {
        OrderMessageTemplates::syncToDatabase();

        return view('notifications.templates', [
            'mailTemplates' => MailTemplate::query()->orderBy('name')->get(),
            'smsTemplates' => SmsTemplate::query()->orderBy('name')->get(),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Mesaj bilgilendirmeleri', 'url' => route('notifications.index')],
                ['label' => 'Şablonlar'],
            ],
        ]);
    }

    /**
     * Update a mail template.
     */
    public function updateMailTemplate(Request $request, MailTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template->update([
            'name' => $validated['name'],
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()
            ->route('notifications.templates')
            ->with('success', 'Mail şablonu güncellendi.');
    }

    /**
     * Update an SMS template.
     */
    public function updateSmsTemplate(Request $request, SmsTemplate $template): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template->update([
            'name' => $validated['name'],
            'body' => $validated['body'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()
            ->route('notifications.templates')
            ->with('success', 'SMS şablonu güncellendi.');
    }
}
