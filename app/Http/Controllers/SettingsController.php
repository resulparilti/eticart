<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\CargoException;
use App\Exceptions\ShopifyException;
use App\Exceptions\UyumSoftException;
use App\Models\CargoCompany;
use App\Models\MailTemplate;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Models\SyncJob;
use App\Services\CargoService;
use App\Services\MailConfigService;
use App\Services\MailService;
use App\Services\ShopifyService;
use App\Services\SmsService;
use App\Services\UyumSoftService;
use App\Support\ShippingLabelProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    /**
     * Settings hub.
     */
    public function index(): View
    {
        return view('settings.index', [
            'status' => [
                'shopify' => filled(Setting::getValue('shopify_store_url')) && filled(Setting::getValue('shopify_access_token')),
                'uyumsoft' => filled(Setting::getValue('uyumsoft_api_user')) && filled(Setting::getValue('uyumsoft_api_password')),
                'cargo' => CargoCompany::query()->where('is_active', true)->exists(),
                'mail' => filled(Setting::getValue('mail_from_address')),
                'sms' => filled(Setting::getValue('sms_provider')),
                'sync' => SyncJob::query()->where('is_active', true)->exists(),
                'general' => filled(Setting::getValue('general_company_name')),
            ],
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Ayarlar'],
            ],
        ]);
    }

    /**
     * General company / return label settings.
     */
    public function general(): View
    {
        $this->ensureGeneralKeys();

        return view('settings.general', [
            'settings' => $this->categoryMap('general'),
            'breadcrumbs' => $this->crumbs('Genel'),
        ]);
    }

    /**
     * Save general settings.
     */
    public function updateGeneral(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'general_app_name' => ['required', 'string', 'max:80'],
            'general_company_name' => ['nullable', 'string', 'max:255'],
            'general_website_url' => ['nullable', 'url', 'max:255'],
            'general_company_address' => ['nullable', 'string', 'max:1000'],
            'general_company_phone' => ['nullable', 'string', 'max:50'],
            'general_return_cargo_name' => ['nullable', 'string', 'max:120'],
            'general_return_cargo_code' => ['nullable', 'string', 'max:80'],
        ]);

        $previousAppName = Setting::appName();

        $this->saveMany($validated, 'general', [
            'general_app_name' => 'Sistem Adı',
            'general_company_name' => 'Firma Adı',
            'general_website_url' => 'Web Sitesi',
            'general_company_address' => 'Firma Adresi',
            'general_company_phone' => 'Firma Telefonu',
            'general_return_cargo_name' => 'İade Kargo Firması',
            'general_return_cargo_code' => 'İade Kargo Numarası',
        ]);

        // Müşteri maillerinde Firma adı kullanılsın.
        Setting::setValue('mail_brand_name', $validated['general_company_name'] !== '' ? $validated['general_company_name'] : $validated['general_app_name'], 'mail', 'Mail Site Adı');
        $currentFromName = trim((string) Setting::getValue('mail_from_name', ''));
        $companyName = trim((string) $validated['general_company_name']);
        if ($currentFromName === '' || in_array($currentFromName, ['EtiCart', $previousAppName], true)) {
            Setting::setValue('mail_from_name', $companyName !== '' ? $companyName : $validated['general_app_name'], 'mail', 'Mail From Name');
        }

        config([
            'app.name' => $validated['general_app_name'],
            'mail.from.name' => Setting::getValue('mail_from_name') ?: $validated['general_app_name'],
        ]);

        return back()->with('success', 'Genel ayarlar kaydedildi.');
    }

    /**
     * Shopify settings form.
     */
    public function shopify(): View
    {
        $oauth = app(\App\Services\ShopifyOAuthService::class);
        $grantedScopes = [];
        $missingFulfillmentScopes = ShopifyService::FULFILLMENT_SCOPES;
        $scopeError = null;

        try {
            $service = new ShopifyService();
            if ($service->isConfigured()) {
                $grantedScopes = $service->getAccessScopes();
                $missingFulfillmentScopes = $service->missingFulfillmentScopes($grantedScopes);
            }
        } catch (\Throwable $e) {
            $scopeError = $e->getMessage();
        }

        return view('settings.shopify', [
            'settings' => $this->categoryMap('shopify'),
            'oauthUrls' => $oauth->dashboardUrls(),
            'oauthConfigured' => $oauth->isOAuthConfigured(),
            'grantedScopes' => $grantedScopes,
            'missingFulfillmentScopes' => $missingFulfillmentScopes,
            'scopeError' => $scopeError,
            'breadcrumbs' => $this->crumbs('Shopify'),
        ]);
    }

    /**
     * Save Shopify settings.
     */
    public function updateShopify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shopify_store_url' => ['nullable', 'string', 'max:255'],
            'shopify_access_token' => ['nullable', 'string', 'max:255'],
            'shopify_api_version' => ['nullable', 'string', 'max:20'],
            'shopify_location_id' => ['nullable', 'string', 'max:50'],
            'shopify_app_url' => ['nullable', 'url', 'max:255'],
            'shopify_api_key' => ['nullable', 'string', 'max:255'],
            'shopify_api_secret' => ['nullable', 'string', 'max:255'],
            'shopify_scopes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->saveMany($validated, 'shopify', [
            'shopify_store_url' => 'Shopify Store URL',
            'shopify_access_token' => 'Shopify Access Token',
            'shopify_api_version' => 'Shopify API Version',
            'shopify_location_id' => 'Shopify Location ID',
            'shopify_app_url' => 'Shopify App Public URL',
            'shopify_api_key' => 'Shopify API Key (Client ID)',
            'shopify_api_secret' => 'Shopify API Secret',
            'shopify_scopes' => 'Shopify OAuth Scopes',
        ]);

        return back()->with('success', 'Shopify ayarları kaydedildi.');
    }

    /**
     * Test Shopify connection.
     */
    public function testShopify(): RedirectResponse
    {
        try {
            // Refresh config from DB for this request.
            Cache::forget('eticart.settings');
            $service = new ShopifyService();
            $shop = $service->testConnection();
            $missing = $service->missingFulfillmentScopes();

            if ($missing !== []) {
                return back()->with(
                    'warning',
                    'Shopify bağlantısı başarılı ('.($shop['name'] ?? 'OK').') ancak siparişi Fulfilled yapmak için izin eksik: '
                    .implode(', ', $missing).'. Custom app’te Admin API izinlerini açıp token’ı yenileyin.'
                );
            }

            return back()->with('success', 'Shopify bağlantısı başarılı: '.($shop['name'] ?? 'OK').'. Fulfillment yetkileri tamam.');
        } catch (ShopifyException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Shopify testi başarısız.');
        }
    }

    /**
     * UyumSoft settings form.
     */
    public function uyumsoft(): View
    {
        return view('settings.uyumsoft', [
            'settings' => $this->categoryMap('uyumsoft'),
            'breadcrumbs' => $this->crumbs('UyumSoft'),
        ]);
    }

    /**
     * Save UyumSoft settings.
     */
    public function updateUyumsoft(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'uyumsoft_api_user' => ['nullable', 'string', 'max:255'],
            'uyumsoft_api_password' => ['nullable', 'string', 'max:255'],
            'uyumsoft_warehouse_id' => ['nullable', 'string', 'max:100'],
            'uyumsoft_branch_code' => ['nullable', 'string', 'max:100'],
            'uyumsoft_base_url' => ['nullable', 'url', 'max:255'],
        ]);

        $this->saveMany($validated, 'uyumsoft', [
            'uyumsoft_api_user' => 'UyumSoft API User',
            'uyumsoft_api_password' => 'UyumSoft API Password',
            'uyumsoft_warehouse_id' => 'UyumSoft Depo Kodu',
            'uyumsoft_branch_code' => 'UyumSoft İşyeri Kodu',
            'uyumsoft_base_url' => 'UyumSoft Base URL',
        ]);

        return back()->with('success', 'UyumSoft ayarları kaydedildi.');
    }

    /**
     * Test UyumSoft connection.
     */
    public function testUyumsoft(): RedirectResponse
    {
        try {
            Cache::forget('eticart.settings');
            $service = new UyumSoftService();
            $result = $service->testConnection();

            return back()->with('success', 'UyumSoft bağlantısı başarılı ('.($result['mode'] ?? 'api').') — '.$result['base_url']);
        } catch (UyumSoftException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'UyumSoft testi başarısız: '.$e->getMessage());
        }
    }

    /**
     * Cargo company settings.
     */
    public function cargo(): View
    {
        return view('settings.cargo', [
            'companies' => CargoCompany::query()
                ->orderByDesc('is_active')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'breadcrumbs' => $this->crumbs('Kargo'),
        ]);
    }

    /**
     * Update cargo companies.
     */
    public function updateCargo(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'companies' => ['required', 'array'],
                'companies.*.id' => ['required', 'exists:cargo_companies,id'],
                'companies.*.api_key' => ['nullable', 'string'],
                'companies.*.api_secret' => ['nullable', 'string'],
                'companies.*.username' => ['nullable', 'string', 'max:255'],
                'companies.*.password' => ['nullable', 'string'],
                'companies.*.sender_username' => ['nullable', 'string', 'max:255'],
                'companies.*.sender_password' => ['nullable', 'string'],
                'companies.*.receiver_username' => ['nullable', 'string', 'max:255'],
                'companies.*.receiver_password' => ['nullable', 'string'],
                'companies.*.customer_code' => ['nullable', 'string', 'max:100'],
                'companies.*.branch_code' => ['nullable', 'string', 'max:100'],
                'companies.*.default_payment_type' => ['nullable', 'in:sender,receiver'],
                'companies.*.endpoint' => ['nullable', 'string', 'max:500'],
                'companies.*.is_active' => ['nullable', 'boolean'],
                'companies.*.is_default' => ['nullable', 'boolean'],
            ]);

            $defaultId = null;

            foreach ($validated['companies'] as $row) {
                /** @var CargoCompany $company */
                $company = CargoCompany::query()->findOrFail($row['id']);
                $settings = is_array($company->settings) ? $company->settings : [];

                $payload = [
                    'is_active' => ! empty($row['is_active']),
                    'is_default' => ! empty($row['is_default']),
                ];

                if ($company->provider_type === 'yurtici') {
                    if (array_key_exists('sender_username', $row)) {
                        $settings['sender_username'] = trim((string) ($row['sender_username'] ?? ''));
                        $payload['username'] = $settings['sender_username'] !== ''
                            ? $settings['sender_username']
                            : $company->username;
                    }
                    if (! empty($row['sender_password'])) {
                        $payload['password'] = $row['sender_password'];
                    }
                    if (array_key_exists('receiver_username', $row)) {
                        $settings['receiver_username'] = trim((string) ($row['receiver_username'] ?? ''));
                        if ($settings['receiver_username'] !== '') {
                            $payload['api_key'] = $settings['receiver_username'];
                        }
                    }
                    if (! empty($row['receiver_password'])) {
                        $payload['api_secret'] = $row['receiver_password'];
                    }
                    if (array_key_exists('customer_code', $row)) {
                        $settings['customer_code'] = trim((string) ($row['customer_code'] ?? ''));
                    }
                    if (array_key_exists('branch_code', $row)) {
                        $settings['branch_code'] = trim((string) ($row['branch_code'] ?? ''));
                    }
                    if (array_key_exists('default_payment_type', $row)) {
                        $settings['default_payment_type'] = $row['default_payment_type'] ?? 'sender';
                    }
                    if (array_key_exists('endpoint', $row) && filled($row['endpoint'] ?? null)) {
                        $settings['endpoint'] = trim((string) $row['endpoint']);
                    }

                    unset($settings['sender_password'], $settings['receiver_password']);
                    $payload['settings'] = $settings;
                } else {
                    if (array_key_exists('username', $row)) {
                        $payload['username'] = $row['username'] ?? null;
                    }
                    if (array_key_exists('api_key', $row) && $row['api_key'] !== null && $row['api_key'] !== '') {
                        $payload['api_key'] = $row['api_key'];
                    }
                    if (array_key_exists('api_secret', $row) && $row['api_secret'] !== null && $row['api_secret'] !== '') {
                        $payload['api_secret'] = $row['api_secret'];
                    }
                    if (array_key_exists('password', $row) && $row['password'] !== null && $row['password'] !== '') {
                        $payload['password'] = $row['password'];
                    }
                }

                if (! empty($row['is_default'])) {
                    $defaultId = (int) $company->id;
                }

                $company->update($payload);
            }

            if ($defaultId) {
                CargoCompany::query()->where('id', '!=', $defaultId)->update(['is_default' => false]);
                CargoCompany::query()->where('id', $defaultId)->update(['is_default' => true]);
            }

            return back()->with('success', 'Kargo ayarları kaydedildi.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('error', 'Kargo ayarları kaydedilemedi: '.$e->getMessage());
        }
    }

    /**
     * Test Yurtiçi SOAP credentials.
     */
    public function testYurtici(Request $request, CargoService $cargoService): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:cargo_companies,id'],
            'payment_type' => ['nullable', 'in:sender,receiver'],
        ]);

        $company = CargoCompany::query()->findOrFail((int) $validated['company_id']);
        if ($company->provider_type !== 'yurtici') {
            $message = 'Bu firma Yurtiçi değil.';
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 422);
            }

            return back()->with('error', $message);
        }

        $provider = $cargoService->resolveProvider($company);
        $result = method_exists($provider, 'testConnection')
            ? $provider->testConnection((string) ($validated['payment_type'] ?? 'sender'))
            : ['success' => false, 'message' => 'Test desteklenmiyor.'];

        if ($request->expectsJson()) {
            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        }

        return back()->with(($result['success'] ?? false) ? 'success' : 'error', $result['message'] ?? 'Test sonucu alınamadı.');
    }

    /**
     * Send a test shipment payload to Yurtiçi SOAP API and return raw response.
     */
    public function testYurticiShipment(Request $request, CargoService $cargoService): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:cargo_companies,id'],
            'payment_type' => ['required', 'in:sender,receiver'],
            'receiver_name' => ['required', 'string', 'min:5', 'max:255'],
            'receiver_address' => ['required', 'string', 'min:10', 'max:500'],
            'receiver_city' => ['required', 'string', 'max:100'],
            'receiver_town' => ['required', 'string', 'max:100'],
            'receiver_phone' => ['required', 'string', 'max:20'],
            'receiver_email' => ['nullable', 'email', 'max:255'],
            'order_number' => ['nullable', 'string', 'max:50'],
            'cargo_key' => ['nullable', 'string', 'max:50'],
            'desi' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'cargo_count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'notes' => ['nullable', 'string', 'max:500'],
            'special_field_1' => ['nullable', 'string', 'max:100'],
            'special_field_2' => ['nullable', 'string', 'max:100'],
            'special_field_3' => ['nullable', 'string', 'max:100'],
        ]);

        $company = CargoCompany::query()->findOrFail((int) $validated['company_id']);
        if ($company->provider_type !== 'yurtici') {
            return response()->json([
                'success' => false,
                'message' => 'Bu firma Yurtiçi değil.',
            ], 422);
        }

        $provider = $cargoService->resolveProvider($company);

        try {
            $result = $provider->createShipment([
                'payment_type' => $validated['payment_type'],
                'receiver_name' => $validated['receiver_name'],
                'receiver_address' => $validated['receiver_address'],
                'receiver_city' => $validated['receiver_city'],
                'receiver_town' => $validated['receiver_town'],
                'receiver_phone' => $validated['receiver_phone'],
                'receiver_email' => $validated['receiver_email'] ?? '',
                'order_number' => $validated['order_number'] ?? null,
                'cargo_key' => $validated['cargo_key'] ?? null,
                'desi' => $validated['desi'] ?? '',
                'weight' => $validated['weight'] ?? 1,
                'cargo_count' => $validated['cargo_count'] ?? 1,
                'notes' => $validated['notes'] ?? '',
                'special_field_1' => $validated['special_field_1'] ?? '',
                'special_field_2' => $validated['special_field_2'] ?? '',
                'special_field_3' => $validated['special_field_3'] ?? '',
                'allow_local_fallback' => false,
                'debug' => true,
            ]);

            return response()->json([
                'success' => (bool) ($result['success'] ?? true),
                'message' => (string) ($result['message'] ?? 'Yurtiçi API yanıtı alındı.'),
                'result' => $result,
            ], ($result['success'] ?? true) ? 200 : 422);
        } catch (CargoException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Test isteği başarısız: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Query Yurtiçi tracking by cargoKey / invoiceKey.
     */
    public function queryYurticiShipment(Request $request, CargoService $cargoService): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:cargo_companies,id'],
            'payment_type' => ['required', 'in:sender,receiver'],
            'key' => ['required', 'string', 'max:50'],
            'key_type' => ['nullable', 'integer', 'in:0,1'],
        ]);

        $company = CargoCompany::query()->findOrFail((int) $validated['company_id']);
        if ($company->provider_type !== 'yurtici') {
            return response()->json([
                'success' => false,
                'message' => 'Bu firma Yurtiçi değil.',
            ], 422);
        }

        $provider = $cargoService->resolveProvider($company);

        if (! method_exists($provider, 'queryShipmentByKey')) {
            return response()->json([
                'success' => false,
                'message' => 'Bu sağlayıcıda takip sorgusu desteklenmiyor.',
            ], 422);
        }

        /** @var \App\Services\Cargo\YurticiCargoService $provider */
        $result = $provider->queryShipmentByKey(
            (string) $validated['key'],
            (int) ($validated['key_type'] ?? 0),
            (string) $validated['payment_type']
        );

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => (string) ($result['message'] ?? 'Sorgu tamamlandı.'),
            'result' => $result,
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Printable Yurtiçi test label (CODE128 of cargoKey).
     */
    public function printYurticiLabel(Request $request, CargoService $cargoService): View
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:cargo_companies,id'],
            'cargo_key' => ['required', 'string', 'max:20'],
            'invoice_key' => ['nullable', 'string', 'max:20'],
            'job_id' => ['nullable', 'string', 'max:50'],
            'receiver_name' => ['nullable', 'string', 'max:255'],
            'receiver_address' => ['nullable', 'string', 'max:500'],
            'receiver_city' => ['nullable', 'string', 'max:100'],
            'receiver_town' => ['nullable', 'string', 'max:100'],
            'receiver_phone' => ['nullable', 'string', 'max:20'],
            'desi' => ['nullable', 'string', 'max:20'],
            'weight' => ['nullable', 'string', 'max:20'],
            'cargo_count' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $company = CargoCompany::query()->findOrFail((int) $validated['company_id']);
        if ($company->provider_type !== 'yurtici') {
            abort(422, 'Bu firma Yurtiçi değil.');
        }

        $provider = $cargoService->resolveProvider($company);
        if (! method_exists($provider, 'buildLabelData')) {
            abort(422, 'Etiket oluşturma desteklenmiyor.');
        }

        /** @var \App\Services\Cargo\YurticiCargoService $provider */
        $label = $provider->buildLabelData($validated);
        $label['company'] = ShippingLabelProfile::company();

        return view('labels.print', [
            'title' => 'Yurtiçi Kargo Barkodu',
            'backUrl' => route('settings.cargo'),
            'labels' => [$label],
        ]);
    }

    /**
     * Mail settings form.
     */
    public function mail(): View
    {
        $settings = [];
        try {
            $this->ensureMailKeys();
            $settings = $this->categoryMap('mail');
        } catch (Throwable $e) {
            report($e);
        }

        $colorDefaults = [
            'mail_header_bg' => '#0f2a3d',
            'mail_header_text' => '#ffffff',
            'mail_text_color' => '#142433',
            'mail_muted_color' => '#5b6b7c',
            'mail_link_color' => '#c45c26',
            'mail_button_bg' => '#0f2a3d',
            'mail_button_text' => '#ffffff',
        ];
        foreach ($colorDefaults as $key => $hex) {
            $settings[$key] = $this->hexColor($settings[$key] ?? null, $hex);
        }

        $brandName = 'EtiCart';
        try {
            $brandName = Setting::appName();
        } catch (Throwable) {
        }

        return view('settings.mail-form', [
            'settings' => $settings,
            'brandName' => $brandName,
            'recentMails' => collect(),
            'fromMismatch' => false,
            'breadcrumbs' => $this->crumbs('Mail'),
        ]);
    }

    /**
     * Save mail settings.
     */
    public function updateMail(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mail_mailer' => ['required', 'in:log,smtp,sendmail'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'mail_brand_name' => ['nullable', 'string', 'max:120'],
            'mail_header_bg' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'mail_header_text' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'mail_text_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'mail_muted_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'mail_link_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'mail_button_bg' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'mail_button_text' => ['nullable', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'mail_logo' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,webp,svg'],
            'mail_smtp_host' => ['nullable', 'string', 'max:255'],
            'mail_smtp_port' => ['nullable', 'integer', 'in:25,465,587,2525'],
            'mail_smtp_username' => ['nullable', 'string', 'max:255'],
            'mail_smtp_password' => ['nullable', 'string', 'max:255'],
            'mail_smtp_encryption' => ['nullable', 'in:tls,ssl,null'],
            'mail_attach_invoice' => ['nullable', 'in:0,1'],
            'mail_send_interval_minutes' => ['nullable', 'integer', 'in:2,3,4,5,6,8,10'],
        ]);

        if (($validated['mail_smtp_encryption'] ?? null) === 'null') {
            $validated['mail_smtp_encryption'] = '';
        }

        $validated['mail_attach_invoice'] = $request->boolean('mail_attach_invoice') ? '1' : '0';

        // Boş şifre gönderilirse mevcut şifreyi koru.
        if (($validated['mail_smtp_password'] ?? '') === '') {
            unset($validated['mail_smtp_password']);
        }

        if ($validated['mail_mailer'] === 'smtp' && blank($validated['mail_smtp_host'] ?? null)) {
            return redirect()
                ->route('settings.mail')
                ->withInput()
                ->with('error', 'SMTP mailer için SMTP Host zorunludur.');
        }

        unset($validated['mail_logo']);

        if ($request->hasFile('mail_logo')) {
            try {
                $file = $request->file('mail_logo');
                $extension = strtolower((string) $file->getClientOriginalExtension());
                \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory('mail');
                $path = $file->storeAs('mail', 'logo.'.$extension, 'public');
                $old = (string) Setting::getValue('mail_logo_path', '');
                if ($old !== '' && $old !== $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($old)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($old);
                }
                $validated['mail_logo_path'] = $path;
            } catch (Throwable $e) {
                report($e);

                return redirect()
                    ->route('settings.mail')
                    ->withInput()
                    ->with('error', 'Logo yüklenemedi: '.$e->getMessage());
            }
        }

        try {
            $this->saveMany($validated, 'mail', [
                'mail_mailer' => 'Mailer',
                'mail_from_address' => 'Mail From Address',
                'mail_from_name' => 'Mail From Name',
                'mail_brand_name' => 'Mail Site Adı',
                'mail_header_bg' => 'Mail Başlık Rengi',
                'mail_header_text' => 'Mail Başlık Yazı Rengi',
                'mail_text_color' => 'Mail Yazı Rengi',
                'mail_muted_color' => 'Mail İkincil Yazı Rengi',
                'mail_link_color' => 'Mail Bağlantı Rengi',
                'mail_button_bg' => 'Mail Buton Rengi',
                'mail_button_text' => 'Mail Buton Yazı Rengi',
                'mail_logo_path' => 'Mail Logo',
                'mail_smtp_host' => 'SMTP Host',
                'mail_smtp_port' => 'SMTP Port',
                'mail_smtp_username' => 'SMTP Username',
                'mail_smtp_password' => 'SMTP Password',
                'mail_smtp_encryption' => 'SMTP Encryption',
                'mail_attach_invoice' => 'Faturayı e-postaya ekle',
                'mail_send_interval_minutes' => 'Gönderimler arası bekleme (dk)',
            ]);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('settings.mail')
                ->withInput()
                ->with('error', 'Mail ayarları kaydedilemedi: '.$e->getMessage());
        }

        app(MailConfigService::class)->applyFromSettings();

        return redirect()
            ->route('settings.mail')
            ->with('success', 'Mail ayarları kaydedildi. Aktif mailer: '.config('mail.default'));
    }

    /**
     * Send test email.
     */
    public function testMail(Request $request, MailService $mailService, MailConfigService $mailConfig): RedirectResponse
    {
        $validated = $request->validate([
            'test_email' => ['required', 'email'],
        ]);

        $mailConfig->applyFromSettings();

        if (config('mail.default') === 'log') {
            return back()->with(
                'error',
                'Mailer şu an "log". Gerçek e-posta gitmez. Mailer olarak sendmail veya smtp seçip kaydedin.'
            );
        }

        try {
            $notification = $mailService->sendCustom(
                $validated['test_email'],
                'EtiCart Test Mail',
                "Merhaba,\n\nBu bir test e-postasıdır.\nSMTP ve HTML şablon ayarlarınız çalışıyor.\n\nTarih: ".now()->format('d.m.Y H:i')
            );

            $reportMessage = method_exists($notification, 'reportMessage')
                ? $notification->reportMessage()
                : (string) ($notification->error ?: $notification->status);
            $report = method_exists($notification, 'mailReport')
                ? $notification->mailReport()
                : [];

            if ($notification->status === 'failed') {
                return redirect()
                    ->route('settings.mail')
                    ->with('error', 'Test maili başarısız: '.$reportMessage);
            }

            $redirect = redirect()
                ->route('settings.mail')
                ->with(
                    'success',
                    $reportMessage
                    .' → '.$validated['test_email']
                    .' · From: '.($report['from'] ?? '-')
                    .' · Mailer: '.($report['mailer'] ?? config('mail.default'))
                );

            if (filled($report['warning'] ?? null)) {
                return $redirect->with('warning', (string) $report['warning']);
            }

            return $redirect;
        } catch (Throwable $e) {
            return back()->with('error', 'Mail gönderimi hatası: '.$e->getMessage());
        }
    }

    /**
     * SMS settings form.
     */
    public function sms(): View
    {
        return view('settings.sms', [
            'settings' => $this->categoryMap('sms'),
            'breadcrumbs' => $this->crumbs('SMS'),
        ]);
    }

    /**
     * Save SMS settings.
     */
    public function updateSms(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sms_provider' => ['required', 'in:log,netgsm,generic'],
            'sms_api_key' => ['nullable', 'string', 'max:255'],
            'sms_api_secret' => ['nullable', 'string', 'max:255'],
            'sms_header' => ['nullable', 'string', 'max:20'],
            'sms_normalize_zero' => ['nullable', 'boolean'],
        ]);

        $validated['sms_normalize_zero'] = $request->boolean('sms_normalize_zero') ? '1' : '0';

        $this->saveMany($validated, 'sms', [
            'sms_provider' => 'SMS Provider',
            'sms_api_key' => 'SMS API Key',
            'sms_api_secret' => 'SMS API Secret',
            'sms_header' => 'SMS Header',
            'sms_normalize_zero' => 'Sıfır Kısaltması',
        ]);

        return back()->with('success', 'SMS ayarları kaydedildi.');
    }

    /**
     * Send test SMS.
     */
    public function testSms(Request $request, SmsService $smsService): RedirectResponse
    {
        $validated = $request->validate([
            'test_phone' => ['required', 'string', 'max:20'],
        ]);

        Cache::forget('eticart.settings');
        $service = new SmsService();
        $service->send($validated['test_phone'], 'EtiCart test SMS');

        return back()->with('success', 'Test SMS gönderildi / loglandı.');
    }

    /**
     * Sync timing settings.
     */
    public function sync(): View
    {
        $cronMin = max(15, (int) config('eticart.schedule_cron_minutes', 15));
        $heartbeatRaw = Setting::getValue('cron_last_heartbeat');
        $cronHeartbeat = null;
        if (is_string($heartbeatRaw) && $heartbeatRaw !== '') {
            try {
                $cronHeartbeat = \Illuminate\Support\Carbon::parse($heartbeatRaw);
            } catch (\Throwable) {
                $cronHeartbeat = null;
            }
        }

        return view('settings.sync', [
            'settings' => $this->categoryMap('sync'),
            'jobs' => SyncJob::query()->orderBy('job_type')->get(),
            'cronMin' => $cronMin,
            'cronHeartbeat' => $cronHeartbeat,
            'cronCommand' => app(\App\Install\WebInstaller::class)->cronCommand(),
            'breadcrumbs' => $this->crumbs('Senkronizasyon'),
        ]);
    }

    /**
     * Save sync settings.
     */
    public function updateSync(Request $request): RedirectResponse
    {
        $minCron = max(15, (int) config('eticart.schedule_cron_minutes', 15));

        $validated = $request->validate([
            'sync_orders_interval' => ['required', 'integer', 'in:15,30,60'],
            'sync_products_interval' => ['required', 'integer', 'in:15,30,60,120'],
            'sync_stock_interval' => ['required', 'integer', 'in:15,30,60'],
            'sync_cargo_interval' => ['required', 'integer', 'in:15,30,60,120'],
            'auto_create_shipment' => ['nullable', 'boolean'],
            'auto_send_tracking' => ['nullable', 'boolean'],
        ]);

        foreach (['sync_orders_interval', 'sync_stock_interval', 'sync_cargo_interval'] as $intervalKey) {
            if ((int) $validated[$intervalKey] < $minCron) {
                $validated[$intervalKey] = $minCron;
            }
        }
        if ((int) $validated['sync_products_interval'] < $minCron) {
            $validated['sync_products_interval'] = max($minCron, 30);
        }

        $validated['auto_create_shipment'] = $request->boolean('auto_create_shipment') ? '1' : '0';
        $validated['auto_send_tracking'] = $request->boolean('auto_send_tracking') ? '1' : '0';

        $this->saveMany($validated, 'sync', [
            'sync_orders_interval' => 'Sipariş Sync (dk)',
            'sync_products_interval' => 'Ürün Sync (dk)',
            'sync_stock_interval' => 'Stok Sync (dk)',
            'sync_cargo_interval' => 'Kargo Sync (dk)',
            'auto_create_shipment' => 'Otomatik Kargo Oluştur',
            'auto_send_tracking' => 'Otomatik Tracking Gönder',
        ]);

        SyncJob::query()->where('job_type', 'order_sync')->update(['interval_minutes' => (int) $validated['sync_orders_interval']]);
        SyncJob::query()->where('job_type', 'product_sync')->update(['interval_minutes' => (int) $validated['sync_products_interval']]);
        SyncJob::query()->where('job_type', 'stock_sync')->update(['interval_minutes' => (int) $validated['sync_stock_interval']]);
        SyncJob::query()->where('job_type', 'cargo_tracking')->update(['interval_minutes' => (int) $validated['sync_cargo_interval']]);

        return back()->with('success', 'Senkronizasyon ayarları kaydedildi.');
    }

    /**
     * Mail templates (settings section).
     */
    public function mailTemplates(): View
    {
        return view('settings.templates.mail', [
            'templates' => MailTemplate::query()->orderBy('name')->get(),
            'breadcrumbs' => $this->crumbs('Mail Şablonları'),
        ]);
    }

    /**
     * Update mail template from settings.
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

        return back()->with('success', 'Mail şablonu güncellendi.');
    }

    /**
     * Send a test mail using the selected template and sample data.
     */
    public function testMailTemplate(Request $request, MailTemplate $template, MailService $mailService): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $notification = $mailService->sendTemplatePreview($template, $validated['email']);

        $ok = $notification->status === 'sent';

        return response()->json([
            'success' => $ok,
            'message' => $ok
                ? 'Test maili gönderildi.'
                : ('Test maili gönderilemedi: '.($notification->error ?: 'Bilinmeyen hata')),
        ], $ok ? 200 : 422);
    }

    /**
     * SMS templates (settings section).
     */
    public function smsTemplates(): View
    {
        return view('settings.templates.sms', [
            'templates' => SmsTemplate::query()->orderBy('name')->get(),
            'breadcrumbs' => $this->crumbs('SMS Şablonları'),
        ]);
    }

    /**
     * Update SMS template from settings.
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

        return back()->with('success', 'SMS şablonu güncellendi.');
    }

    /**
     * Send a test SMS using the selected template and sample data.
     */
    public function testSmsTemplate(Request $request, SmsTemplate $template, SmsService $smsService): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $notification = $smsService->sendTemplatePreview($template, $validated['phone']);
        $ok = $notification->status === 'sent';

        return response()->json([
            'success' => $ok,
            'message' => $ok
                ? 'Test SMS gönderildi (veya log kanalına yazıldı).'
                : ('Test SMS gönderilemedi: '.($notification->error ?: 'Bilinmeyen hata')),
        ], $ok ? 200 : 422);
    }

    /**
     * @return array<string, string|null>
     */
    private function categoryMap(string $category): array
    {
        return Setting::query()
            ->where('category', $category)
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $labels
     */
    private function saveMany(array $values, string $category, array $labels): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            Setting::setValue($key, (string) $value, $category, $labels[$key] ?? null);
        }
    }

    /**
     * @return array<int, array{label: string, url?: string}>
     */
    private function crumbs(string $label): array
    {
        return [
            ['label' => 'Dashboard', 'url' => route('dashboard')],
            ['label' => 'Ayarlar', 'url' => route('settings.index')],
            ['label' => $label],
        ];
    }

    private function hexColor(mixed $value, string $default): string
    {
        $value = trim((string) $value);

        return preg_match('/^#([0-9a-fA-F]{6})$/', $value) === 1 ? $value : $default;
    }

    private function ensureGeneralKeys(): void
    {
        $defaults = [
            'general_app_name' => ['value' => (string) config('app.name', 'EtiCart'), 'label' => 'Sistem Adı'],
            'general_company_name' => ['value' => '', 'label' => 'Firma Adı'],
            'general_website_url' => ['value' => '', 'label' => 'Web Sitesi'],
            'general_company_address' => ['value' => '', 'label' => 'Firma Adresi'],
            'general_company_phone' => ['value' => '', 'label' => 'Firma Telefonu'],
            'general_return_cargo_name' => ['value' => 'Yurtiçi Kargo', 'label' => 'İade Kargo Firması'],
            'general_return_cargo_code' => ['value' => '', 'label' => 'İade Kargo Numarası'],
        ];

        foreach ($defaults as $key => $meta) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'value' => $meta['value'],
                    'category' => 'general',
                    'label' => $meta['label'],
                ]
            );
        }
    }

    private function ensureMailKeys(): void
    {
        $defaults = [
            'mail_mailer' => ['value' => (string) config('mail.default', 'log'), 'label' => 'Mailer'],
            'mail_from_address' => ['value' => (string) config('mail.from.address', ''), 'label' => 'Mail From Address'],
            'mail_from_name' => ['value' => (string) config('mail.from.name', 'EtiCart'), 'label' => 'Mail From Name'],
            'mail_smtp_host' => ['value' => (string) config('mail.mailers.smtp.host', ''), 'label' => 'SMTP Host'],
            'mail_smtp_port' => ['value' => (string) config('mail.mailers.smtp.port', 587), 'label' => 'SMTP Port'],
            'mail_smtp_username' => ['value' => (string) config('mail.mailers.smtp.username', ''), 'label' => 'SMTP Username'],
            'mail_smtp_password' => ['value' => '', 'label' => 'SMTP Password'],
            'mail_smtp_encryption' => ['value' => in_array(config('mail.mailers.smtp.encryption'), ['tls', 'ssl'], true) ? (string) config('mail.mailers.smtp.encryption') : 'tls', 'label' => 'SMTP Encryption'],
            'mail_brand_name' => ['value' => (string) config('app.name', 'EtiCart'), 'label' => 'Mail Site Adı'],
            'mail_header_bg' => ['value' => '#0f2a3d', 'label' => 'Mail Başlık Rengi'],
            'mail_header_text' => ['value' => '#ffffff', 'label' => 'Mail Başlık Yazı Rengi'],
            'mail_text_color' => ['value' => '#142433', 'label' => 'Mail Yazı Rengi'],
            'mail_muted_color' => ['value' => '#5b6b7c', 'label' => 'Mail İkincil Yazı Rengi'],
            'mail_link_color' => ['value' => '#c45c26', 'label' => 'Mail Bağlantı Rengi'],
            'mail_button_bg' => ['value' => '#0f2a3d', 'label' => 'Mail Buton Rengi'],
            'mail_button_text' => ['value' => '#ffffff', 'label' => 'Mail Buton Yazı Rengi'],
            'mail_logo_path' => ['value' => '', 'label' => 'Mail Logo'],
            'mail_attach_invoice' => ['value' => '0', 'label' => 'Faturayı e-postaya ekle'],
            'mail_send_interval_minutes' => ['value' => '3', 'label' => 'Gönderimler arası bekleme (dk)'],
        ];

        foreach ($defaults as $key => $meta) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                [
                    'value' => $meta['value'],
                    'category' => 'mail',
                    'label' => $meta['label'],
                ]
            );
        }

        Setting::query()->firstOrCreate(
            ['key' => 'sms_normalize_zero'],
            ['value' => '1', 'category' => 'sms', 'label' => 'Sıfır Kısaltması']
        );

        Setting::query()->firstOrCreate(
            ['key' => 'shopify_location_id'],
            ['value' => '', 'category' => 'shopify', 'label' => 'Shopify Location ID']
        );
    }
}
