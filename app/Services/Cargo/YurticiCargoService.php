<?php

declare(strict_types=1);

namespace App\Services\Cargo;

use App\Exceptions\CargoException;
use App\Support\CargoKeyGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

class YurticiCargoService extends AbstractCargoService
{
    private const DEFAULT_ENDPOINT = 'http://webservices.yurticikargo.com:8080/KOPSWebServices/ShippingOrderDispatcherServices';

    public function isConfigured(): bool
    {
        $settings = $this->company->settings ?? [];

        $hasSender = filled($settings['sender_username'] ?? $this->company->username)
            && (
                filled($settings['sender_password'] ?? null)
                || $this->company->hasStoredCredential('password')
            );
        $hasReceiver = filled($settings['receiver_username'] ?? null)
            && (
                filled($settings['receiver_password'] ?? null)
                || $this->company->hasStoredCredential('api_secret')
            );

        return $hasSender || $hasReceiver;
    }

    /**
     * Lightweight credential / endpoint check for settings UI.
     *
     * @return array{success: bool, message: string, mode?: string}
     */
    public function testConnection(string $paymentType = 'sender'): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Yurtiçi kullanıcı adı / şifre tanımlı değil.',
            ];
        }

        [$wsUser, $wsPass] = $this->credentialsForPaymentType($paymentType);
        if ($wsUser === '' || $wsPass === '') {
            return [
                'success' => false,
                'message' => 'Seçilen ödeme tipi için kimlik bilgisi eksik.',
            ];
        }

        try {
            $response = $this->callSoap('queryShipment', [
                'wsUserName' => $wsUser,
                'wsPassword' => $wsPass,
                'wsLanguage' => 'TR',
                'keys' => ['CONNECTIONTEST'],
                'keyType' => 0,
                'addHistoricalData' => false,
                'onlyTracking' => true,
            ]);

            if (stripos($response, 'Fault') !== false && stripos($response, 'faultstring') !== false) {
                $fault = $this->extractXmlValue($response, ['faultstring', 'outResult', 'errMessage']) ?? 'SOAP Fault';

                return [
                    'success' => false,
                    'message' => 'Yurtiçi kimlik doğrulama hatası: '.$fault,
                ];
            }

            return [
                'success' => true,
                'mode' => 'api',
                'message' => 'Yurtiçi SOAP bağlantısı başarılı ('.$paymentType.').',
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Bağlantı testi başarısız: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createShipment(array $data): array
    {
        if (! $this->isConfigured()) {
            return $this->createLocalShipment($data);
        }

        $paymentType = (string) ($data['payment_type'] ?? $this->setting('default_payment_type', 'sender'));
        [$wsUser, $wsPass] = $this->credentialsForPaymentType($paymentType);

        if ($wsUser === '' || $wsPass === '') {
            throw new CargoException('Seçilen ödeme tipi için Yurtiçi kullanıcı adı/şifre tanımlı değil.');
        }

        $receiverName = trim((string) ($data['receiver_name'] ?? ''));
        $receiverAddress = trim((string) ($data['receiver_address'] ?? ''));
        $city = trim((string) ($data['receiver_city'] ?? ''));
        $town = trim((string) ($data['receiver_town'] ?? $this->setting('default_town', '') ?: ''));
        $phone = $this->normalizePhone((string) ($data['receiver_phone'] ?? ''));

        $this->assertCreatePayload($receiverName, $receiverAddress, $city, $town, $phone);

        $providedKey = trim((string) ($data['cargo_key'] ?? ''));
        $orderNumber = (string) ($data['order_number'] ?? '');
        if ($providedKey !== '') {
            // Manuel / test anahtarı: alfanümerik kabul; otomatik üretim sayısal.
            $cargoKey = preg_replace('/[^A-Za-z0-9\-]/', '', $providedKey) ?: CargoKeyGenerator::generateUnique($orderNumber);
            $cargoKey = Str::limit($cargoKey, CargoKeyGenerator::MAX_LENGTH, '');
        } else {
            $cargoKey = CargoKeyGenerator::generateUnique($orderNumber);
        }
        $invoiceKey = $orderNumber !== '' ? $orderNumber : $cargoKey;
        $invoiceKey = preg_replace('/[^A-Za-z0-9\-]/', '', $invoiceKey) ?: $cargoKey;
        $invoiceKey = Str::limit($invoiceKey, 20, '');

        $customerCode = trim((string) $this->setting('customer_code', ''));
        $branchCode = trim((string) $this->setting('branch_code', ''));
        // specialField1 Yurtiçi'de serbest metin değil; doluysa xx$xxx# formatı zorunlu.
        // Müşteri/şube kodunu buraya yazmayın — aksi halde API reddeder.
        $special1 = trim((string) ($data['special_field_1'] ?? $this->setting('special_field_1', '')));
        $special2 = trim((string) ($data['special_field_2'] ?? $this->setting('special_field_2', '')));
        $special3 = trim((string) ($data['special_field_3'] ?? $data['notes'] ?? $this->setting('special_field_3', '')));

        $shippingOrder = [
            'cargoKey' => $cargoKey,
            'invoiceKey' => $invoiceKey,
            'receiverCustName' => $receiverName,
            'receiverAddress' => $receiverAddress,
            'cityName' => mb_strtoupper($city, 'UTF-8'),
            'townName' => mb_strtoupper($town !== '' ? $town : $city, 'UTF-8'),
            'receiverPhone1' => $phone,
            'emailAddress' => (string) ($data['receiver_email'] ?? ''),
            'desi' => (string) ($data['desi'] ?? ''),
            'kg' => (string) ($data['weight'] ?? '1'),
            'cargoCount' => (string) ($data['cargo_count'] ?? '1'),
            'description' => (string) ($data['notes'] ?? ''),
        ];

        if ($special1 !== '') {
            $shippingOrder['specialField1'] = $special1;
        }
        if ($special2 !== '') {
            $shippingOrder['specialField2'] = $special2;
        }
        if ($special3 !== '') {
            $shippingOrder['specialField3'] = Str::limit($special3, 40, '');
        }
        if ($customerCode !== '') {
            $shippingOrder['custProdId'] = $customerCode;
        }
        // branch_code ayarda saklanır; SOAP createShipment alanına map edilmez (özel sözleşme alanı).
        unset($branchCode);

        try {
            $response = $this->callSoap('createShipment', [
                'wsUserName' => $wsUser,
                'wsPassword' => $wsPass,
                'userLanguage' => 'TR',
                'ShippingOrderVO' => $shippingOrder,
            ]);

            $parsed = $this->parseCreateResponse($response, $cargoKey);

            if (! ($parsed['success'] ?? false)) {
                $message = $parsed['message'] ?? 'Yurtiçi gönderi oluşturulamadı.';
                Log::channel('stack')->warning('Yurtiçi createShipment failed', [
                    'message' => $message,
                    'cargo_key' => $cargoKey,
                    'invoice_key' => $invoiceKey,
                ]);

                if ($this->isDuplicateShipmentMessage($message)) {
                    $recovered = $this->recoverExistingShipment(
                        $invoiceKey,
                        $paymentType,
                        $message,
                        $data,
                        $receiverName,
                        $receiverAddress,
                        $city,
                        $town,
                        $phone
                    );

                    if ($recovered !== null) {
                        if (! empty($data['debug'])) {
                            $recovered['raw_xml'] = $response;
                            $recovered['request_payload'] = $shippingOrder;
                        }

                        return $recovered;
                    }
                }

                if (($data['debug'] ?? false) === true) {
                    return [
                        'success' => false,
                        'mode' => 'api',
                        'message' => $message,
                        'cargo_key' => $cargoKey,
                        'raw' => $parsed['raw'] ?? $response,
                        'raw_xml' => $response,
                        'request_payload' => $shippingOrder,
                    ];
                }

                if (($data['allow_local_fallback'] ?? false) === true) {
                    return $this->createLocalShipment($data);
                }

                throw new CargoException($message);
            }

            $result = [
                'success' => true,
                'mode' => 'api',
                'payment_type' => $paymentType,
                'cargo_key' => $cargoKey,
                'invoice_key' => $invoiceKey,
                'job_id' => $parsed['job_id'] ?? null,
                'out_flag' => $parsed['out_flag'] ?? null,
                'barcode_value' => $cargoKey,
                'barcode_format' => 'CODE128',
                'message' => $parsed['message'] ?? 'Gönderi kaydı oluşturuldu.',
                'label_content' => null,
                'receiver' => [
                    'name' => $receiverName,
                    'address' => $receiverAddress,
                    'city' => mb_strtoupper($city, 'UTF-8'),
                    'town' => mb_strtoupper($town !== '' ? $town : $city, 'UTF-8'),
                    'phone' => $phone,
                    'email' => (string) ($data['receiver_email'] ?? ''),
                ],
                'package' => [
                    'desi' => (string) ($data['desi'] ?? ''),
                    'kg' => (string) ($data['weight'] ?? '1'),
                    'cargo_count' => (string) ($data['cargo_count'] ?? '1'),
                ],
                'raw' => $parsed['raw'] ?? $response,
            ];

            // createShipment yalnızca sipariş kabul eder; gerçek takip no için hemen sorgula.
            $query = $this->queryShipmentByKey($cargoKey, 0, $paymentType);
            $result['query'] = $query;
            $result['tracking_ready'] = (bool) ($query['tracking_ready'] ?? false);
            $result['doc_id'] = $query['doc_id'] ?? null;

            if ($result['tracking_ready'] && filled($query['tracking_number'] ?? null)) {
                $result['tracking_number'] = (string) $query['tracking_number'];
                $result['tracking_url'] = (string) ($query['tracking_url'] ?? $this->buildTrackingUrl($result['tracking_number']));
                $result['message'] = 'Gönderi oluşturuldu ve takip numarası alındı: '.$result['tracking_number'];
            } else {
                // Yurtiçi çoğu zaman takip numarasını şube işlemi sonrası üretir.
                // Referans olarak cargoKey saklanır; site sorgusu henüz çalışmayabilir.
                $result['tracking_number'] = $cargoKey;
                $result['tracking_url'] = $this->buildTrackingUrl($cargoKey);
                $result['message'] = 'Gönderi siparişi oluşturuldu (jobId: '.($result['job_id'] ?? '-').'). '
                    .'Halka açık takip numarası henüz yok; Yurtiçi bunu genelde şube kabulünden sonra verir. '
                    .'Referans anahtar (cargoKey): '.$cargoKey;
            }

            if (! empty($data['debug'])) {
                $result['raw_xml'] = $response;
                $result['request_payload'] = $shippingOrder;
                $result['query_raw_xml'] = $query['raw_xml'] ?? null;
            }

            return $result;
        } catch (CargoException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::channel('stack')->error('Yurtiçi createShipment exception', [
                'message' => $e->getMessage(),
            ]);

            throw new CargoException('Yurtiçi API bağlantı hatası: '.$e->getMessage(), [], 0, $e);
        }
    }

    public function getTrackingInfo(string $trackingNumber): array
    {
        if (! $this->isConfigured()) {
            return $this->localTrackingInfo($trackingNumber);
        }

        $paymentType = (string) $this->setting('default_payment_type', 'sender');

        try {
            return $this->queryShipmentByKey($trackingNumber, 0, $paymentType);
        } catch (Throwable $e) {
            Log::channel('stack')->warning('Yurtiçi queryShipment failed', [
                'message' => $e->getMessage(),
            ]);

            return $this->localTrackingInfo($trackingNumber);
        }
    }

    /**
     * Query shipment by cargoKey (0) or invoiceKey (1).
     *
     * @return array<string, mixed>
     */
    public function queryShipmentByKey(string $key, int $keyType = 0, string $paymentType = 'sender'): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'tracking_ready' => false,
                'message' => 'Yurtiçi kimlik bilgileri tanımlı değil.',
            ];
        }

        [$wsUser, $wsPass] = $this->credentialsForPaymentType($paymentType);
        if ($wsUser === '' || $wsPass === '') {
            return [
                'success' => false,
                'tracking_ready' => false,
                'message' => 'Seçilen ödeme tipi için kimlik bilgisi eksik.',
            ];
        }

        try {
            // queryShipmentDetail takip URL / docId alanlarını daha zengin döner.
            $response = $this->callSoap('queryShipmentDetail', [
                'wsUserName' => $wsUser,
                'wsPassword' => $wsPass,
                'wsLanguage' => 'TR',
                'keys' => [$key],
                'keyType' => $keyType,
                'addHistoricalData' => true,
                'onlyTracking' => false,
                'jsonData' => false,
            ]);

            return $this->parseQueryDetailResponse($response, $key);
        } catch (Throwable $e) {
            // Detail endpoint bazı hesaplarda kapalı olabilir; basit query'ye düş.
            try {
                return $this->queryShipment($key, $keyType, $paymentType);
            } catch (Throwable $inner) {
                return [
                    'success' => false,
                    'tracking_ready' => false,
                    'cargo_key' => $key,
                    'message' => 'Takip sorgusu başarısız: '.$inner->getMessage(),
                ];
            }
        }
    }

    /**
     * queryShipment (keyType=0 cargoKey) — kayıt doğrulama ve operationStatus için.
     *
     * @return array<string, mixed>
     */
    public function queryShipment(string $key, int $keyType = 0, string $paymentType = 'sender'): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'registered' => false,
                'tracking_ready' => false,
                'message' => 'Yurtiçi kimlik bilgileri tanımlı değil.',
            ];
        }

        [$wsUser, $wsPass] = $this->credentialsForPaymentType($paymentType);
        if ($wsUser === '' || $wsPass === '') {
            return [
                'success' => false,
                'registered' => false,
                'tracking_ready' => false,
                'message' => 'Seçilen ödeme tipi için kimlik bilgisi eksik.',
            ];
        }

        $response = $this->callSoap('queryShipment', [
            'wsUserName' => $wsUser,
            'wsPassword' => $wsPass,
            'wsLanguage' => 'TR',
            'keys' => [$key],
            'keyType' => $keyType,
            'addHistoricalData' => true,
            'onlyTracking' => false,
        ]);

        return $this->parseQueryDetailResponse($response, $key);
    }

    /**
     * Printable Yurtiçi label data for cargoKey barcode.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function buildLabelData(array $data): array
    {
        $cargoKey = CargoKeyGenerator::sanitize(
            (string) ($data['cargo_key'] ?? $data['barcode_value'] ?? '')
        );

        if ($cargoKey === '') {
            throw new CargoException('Etiket için sayısal cargoKey gerekli.');
        }

        $jobId = trim((string) ($data['job_id'] ?? ''));

        return [
            'provider' => 'yurtici',
            'title' => 'Yurtiçi Kargo Etiketi',
            'cargo_key' => $cargoKey,
            'invoice_key' => (string) ($data['invoice_key'] ?? $data['order_number'] ?? ''),
            'barcode_value' => $cargoKey,
            'barcode_format' => 'CODE128',
            'job_barcode_value' => $jobId !== '' ? $jobId : null,
            'customer_code' => (string) $this->setting('customer_code', ''),
            'job_id' => $jobId !== '' ? $jobId : null,
            'receiver_name' => (string) ($data['receiver_name'] ?? data_get($data, 'receiver.name', '')),
            'receiver_address' => (string) ($data['receiver_address'] ?? data_get($data, 'receiver.address', '')),
            'receiver_city' => (string) ($data['receiver_city'] ?? data_get($data, 'receiver.city', '')),
            'receiver_town' => (string) ($data['receiver_town'] ?? data_get($data, 'receiver.town', '')),
            'receiver_phone' => (string) ($data['receiver_phone'] ?? data_get($data, 'receiver.phone', '')),
            'desi' => (string) ($data['desi'] ?? data_get($data, 'package.desi', '')),
            'kg' => (string) ($data['weight'] ?? data_get($data, 'package.kg', '')),
            'cargo_count' => (string) ($data['cargo_count'] ?? data_get($data, 'package.cargo_count', '1')),
            'notes' => (string) ($data['notes'] ?? ''),
            'hint' => 'Şube bu CODE128 barkodu (cargoKey) okutarak gönderiyi kabul eder.',
        ];
    }

    public function generateLabel(int|string $shipmentId): string
    {
        $path = 'cargo_labels/yurtici_'.$shipmentId.'.txt';
        Storage::disk('local')->put($path, "Yurtiçi Kargo Label #{$shipmentId}");

        return $path;
    }

    public function generateInvoice(int|string $shipmentId): string
    {
        $path = 'invoices/yurtici_'.$shipmentId.'.txt';
        Storage::disk('local')->put($path, "Yurtiçi Kargo Invoice #{$shipmentId}");

        return $path;
    }

    public function cancelShipment(string $trackingNumber): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        [$wsUser, $wsPass] = $this->credentialsForPaymentType(
            (string) $this->setting('default_payment_type', 'sender')
        );

        try {
            $response = $this->callSoap('cancelShipment', [
                'wsUserName' => $wsUser,
                'wsPassword' => $wsPass,
                'userLanguage' => 'TR',
                'cargoKeys' => $trackingNumber,
            ]);

            $parsed = $this->parseCreateResponse($response, $trackingNumber);
            if ($parsed['success'] ?? false) {
                return true;
            }

            $message = trim((string) ($parsed['message'] ?? 'İptal başarısız.'));
            if ($this->messageIndicatesBranchAcceptance($message)) {
                throw new CargoException('Kargo şubeye teslim edilmiş. İptal yapılamaz. '.$message);
            }

            throw new CargoException('Kargo iptali başarısız: '.$message);
        } catch (CargoException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::channel('stack')->error('Yurtiçi cancelShipment failed', [
                'message' => $e->getMessage(),
            ]);

            throw new CargoException('Yurtiçi iptal API hatası: '.$e->getMessage(), [], 0, $e);
        }
    }

    /**
     * Yurtiçi aynı invoiceKey / jobId ile ikinci create'i reddeder.
     */
    private function isDuplicateShipmentMessage(string $message): bool
    {
        $haystack = mb_strtolower($message, 'UTF-8');

        return str_contains($haystack, 'sistemde mevcut')
            || str_contains($haystack, 'mevcuttur')
            || str_contains($haystack, 'already exist')
            || str_contains($haystack, 'duplicate');
    }

    /**
     * @return array{job_id: ?string, invoice_key: ?string}
     */
    private function parseDuplicateIds(string $message): array
    {
        $jobId = null;
        $invoiceKey = null;

        if (preg_match('/(\d+)\s*talep nolu\s*\(\s*JOB_ID\s*\)/iu', $message, $m) === 1) {
            $jobId = $m[1];
        }
        if (preg_match('/(\d+)\s*ft\.?\s*nolu\s*\(\s*INVOICE_KEY\s*\)/iu', $message, $m) === 1) {
            $invoiceKey = $m[1];
        }

        return [
            'job_id' => $jobId,
            'invoice_key' => $invoiceKey,
        ];
    }

    /**
     * Yerel kargo kaydı silinse bile Yurtiçi'nde duran gönderiyi invoiceKey ile çek.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function recoverExistingShipment(
        string $invoiceKey,
        string $paymentType,
        string $errorMessage,
        array $data,
        string $receiverName,
        string $receiverAddress,
        string $city,
        string $town,
        string $phone
    ): ?array {
        $ids = $this->parseDuplicateIds($errorMessage);
        $lookupInvoice = $ids['invoice_key'] ?: $invoiceKey;

        $query = $this->queryShipmentByKey($lookupInvoice, 1, $paymentType);

        if (! ($query['success'] ?? false) && filled($invoiceKey) && $invoiceKey !== $lookupInvoice) {
            $query = $this->queryShipmentByKey($invoiceKey, 1, $paymentType);
        }

        $cargoKey = trim((string) ($query['cargo_key'] ?? ''));
        if ($cargoKey === '' || $cargoKey === $lookupInvoice) {
            // invoiceKey sorgusu cargoKey döndürmediyse jobId / ham yanıt yetersiz.
            if (! ($query['success'] ?? false)) {
                Log::channel('stack')->warning('Yurtiçi duplicate shipment recover failed', [
                    'invoice_key' => $lookupInvoice,
                    'message' => $query['message'] ?? null,
                ]);

                return null;
            }
        }

        if ($cargoKey !== '' && $cargoKey !== $lookupInvoice) {
            $byCargo = $this->queryShipmentByKey($cargoKey, 0, $paymentType);
            if ($byCargo['success'] ?? false) {
                $query = $byCargo;
            }
        }

        $resolvedCargoKey = trim((string) ($query['cargo_key'] ?? $cargoKey));
        if ($resolvedCargoKey === '') {
            return null;
        }

        $jobId = $query['job_id'] ?? $ids['job_id'];
        $trackingReady = (bool) ($query['tracking_ready'] ?? false);

        $result = [
            'success' => true,
            'recovered' => true,
            'mode' => 'api',
            'payment_type' => $paymentType,
            'cargo_key' => $resolvedCargoKey,
            'invoice_key' => $lookupInvoice,
            'job_id' => $jobId,
            'out_flag' => $query['out_flag'] ?? null,
            'barcode_value' => $resolvedCargoKey,
            'barcode_format' => 'CODE128',
            'query' => $query,
            'tracking_ready' => $trackingReady,
            'doc_id' => $query['doc_id'] ?? null,
            'receiver' => [
                'name' => $receiverName,
                'address' => $receiverAddress,
                'city' => mb_strtoupper($city, 'UTF-8'),
                'town' => mb_strtoupper($town !== '' ? $town : $city, 'UTF-8'),
                'phone' => $phone,
                'email' => (string) ($data['receiver_email'] ?? ''),
            ],
            'package' => [
                'desi' => (string) ($data['desi'] ?? ''),
                'kg' => (string) ($data['weight'] ?? '1'),
                'cargo_count' => (string) ($data['cargo_count'] ?? '1'),
            ],
            'raw' => $query['raw'] ?? null,
        ];

        if ($trackingReady && filled($query['tracking_number'] ?? null)) {
            $result['tracking_number'] = (string) $query['tracking_number'];
            $result['tracking_url'] = (string) ($query['tracking_url'] ?? $this->buildTrackingUrl($result['tracking_number']));
        } else {
            $result['tracking_number'] = $resolvedCargoKey;
            $result['tracking_url'] = $this->buildTrackingUrl($resolvedCargoKey);
        }

        $result['message'] = 'Bu sipariş Yurtiçi sisteminde zaten kayıtlıydı (jobId: '.($jobId ?? '-').'). '
            .'Yerel kargo kaydı mevcut gönderiden geri yüklendi. cargoKey: '.$resolvedCargoKey;

        Log::channel('stack')->info('Yurtiçi existing shipment recovered', [
            'invoice_key' => $lookupInvoice,
            'cargo_key' => $resolvedCargoKey,
            'job_id' => $jobId,
        ]);

        return $result;
    }

    private function messageIndicatesBranchAcceptance(string $message): bool
    {
        $text = mb_strtolower($message, 'UTF-8');

        foreach (['şube', 'sube', 'kabul', 'teslim', 'işlem gör', 'islem gor'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentialsForPaymentType(string $paymentType): array
    {
        $settings = $this->company->settings ?? [];

        if ($paymentType === 'receiver') {
            $user = (string) ($settings['receiver_username'] ?? $this->company->readCredential('api_key') ?? '');
            $pass = (string) ($settings['receiver_password'] ?? $this->company->readCredential('api_secret') ?? '');
        } else {
            $user = (string) ($settings['sender_username'] ?? $this->company->username ?? '');
            $pass = (string) ($settings['sender_password'] ?? $this->company->readCredential('password') ?? '');
        }

        return [trim($user), trim($pass)];
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->company->settings ?? [], $key, $default);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '90') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    private function assertCreatePayload(
        string $receiverName,
        string $receiverAddress,
        string $city,
        string $town,
        string $phone
    ): void {
        $errors = [];

        if (mb_strlen($receiverName) < 5) {
            $errors[] = 'Alıcı adı en az 5 karakter olmalı.';
        }

        if (mb_strlen($receiverAddress) < 10) {
            $errors[] = 'Adres en az 10 karakter olmalı.';
        }

        if ($city === '' || preg_match('/^\d+$/', $city)) {
            $errors[] = 'Şehir (il) zorunludur; posta kodu il olarak gönderilemez.';
        }

        if ($town === '' || preg_match('/^\d+$/', $town)) {
            $errors[] = 'İlçe zorunludur (Yurtiçi); posta kodu ilçe olarak gönderilemez.';
        }

        if (! preg_match('/^0\d{10}$/', $phone)) {
            $errors[] = 'Telefon 11 haneli olmalı (örn. 05551234567).';
        }

        if ($errors !== []) {
            throw new CargoException(implode(' ', $errors));
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function callSoap(string $method, array $params): string
    {
        $endpoint = rtrim((string) $this->setting('endpoint', self::DEFAULT_ENDPOINT), '?');
        $endpoint = str_replace('?wsdl', '', $endpoint);

        $bodyInner = $this->arrayToSoapXml($params);
        $envelope = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ship="http://yurticikargo.com.tr/ShippingOrderDispatcherServices">
  <soapenv:Header/>
  <soapenv:Body>
    <ship:{$method}>
      {$bodyInner}
    </ship:{$method}>
  </soapenv:Body>
</soapenv:Envelope>
XML;

        Log::channel('stack')->info('Yurtiçi SOAP request', [
            'method' => $method,
            'endpoint' => $endpoint,
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction' => '""',
            'Accept' => 'text/xml',
        ])->withBody($envelope, 'text/xml; charset=utf-8')
            ->timeout(45)
            ->post($endpoint);

        if ($response->failed()) {
            throw new CargoException('Yurtiçi SOAP HTTP '.$response->status().': '.Str::limit($response->body(), 300));
        }

        return $response->body();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function arrayToSoapXml(array $data): string
    {
        $xml = '';
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        $xml .= '<'.$key.'>'.(is_array($item) ? $this->arrayToSoapXml($item) : $this->xmlEscape((string) $item)).'</'.$key.'>';
                    }
                } else {
                    $xml .= '<'.$key.'>'.$this->arrayToSoapXml($value).'</'.$key.'>';
                }
            } elseif (is_bool($value)) {
                $xml .= '<'.$key.'>'.($value ? 'true' : 'false').'</'.$key.'>';
            } else {
                $xml .= '<'.$key.'>'.$this->xmlEscape((string) $value).'</'.$key.'>';
            }
        }

        return $xml;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function xmlToArray(string $xml): array
    {
        $normalized = preg_replace('/(<\/?)(\w+):/', '$1', $xml) ?? $xml;
        $sx = new SimpleXMLElement($normalized);

        /** @var array<string, mixed> $json */
        $json = json_decode(json_encode($sx), true) ?: [];

        return $json;
    }

    /**
     * @return array{success: bool, tracking_number?: string, message?: string, job_id?: mixed, raw?: mixed}
     */
    private function parseCreateResponse(string $xml, string $cargoKey): array
    {
        try {
            $json = $this->xmlToArray($xml);

            if (isset($json['Body']['Fault']) || (stripos($xml, 'faultstring') !== false)) {
                $fault = (string) ($this->findKey($json, ['faultstring', 'outResult', 'errMessage']) ?? 'SOAP Fault');

                return [
                    'success' => false,
                    'message' => $fault,
                    'raw' => $json,
                ];
            }

            $outFlag = $this->findKey($json, ['outFlag']);
            $outResult = (string) ($this->findKey($json, ['outResult', 'operationMessage']) ?? '');
            $errCode = (string) ($this->findKey($json, ['errCode', 'errorCode']) ?? '');
            $errMessage = (string) ($this->findKey($json, ['errMessage']) ?? '');
            $jobId = $this->findKey($json, ['jobId', 'JobId']);

            $success = in_array((string) $outFlag, ['0', '00'], true);

            if ($errCode !== '' && $errCode !== '0') {
                $success = false;
            }

            if ($outResult !== '' && (stripos($outResult, 'hata') !== false || stripos($outResult, 'error') !== false)) {
                $success = false;
            }

            if ($jobId && in_array((string) $outFlag, ['0', '00', ''], true) && ($errCode === '' || $errCode === '0')) {
                $success = true;
            }

            $message = $errMessage !== '' ? $errMessage : ($outResult !== '' ? $outResult : ($success ? 'Başarılı' : 'Yurtiçi gönderi yanıtı başarısız.'));

            return [
                'success' => $success,
                'tracking_number' => $cargoKey,
                'job_id' => $jobId,
                'out_flag' => $outFlag,
                'message' => $message,
                'raw' => $json,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Yurtiçi yanıtı parse edilemedi: '.$e->getMessage(),
                'raw' => $xml,
            ];
        }
    }

    /**
     * Parse queryShipment / queryShipmentDetail response.
     *
     * @return array<string, mixed>
     */
    private function parseQueryDetailResponse(string $xml, string $fallbackKey): array
    {
        try {
            $json = $this->xmlToArray($xml);

            if (isset($json['Body']['Fault']) || stripos($xml, 'faultstring') !== false) {
                $fault = (string) ($this->findKey($json, ['faultstring', 'outResult', 'errMessage']) ?? 'SOAP Fault');

                return [
                    'success' => false,
                    'tracking_ready' => false,
                    'cargo_key' => $fallbackKey,
                    'message' => $fault,
                    'raw' => $json,
                    'raw_xml' => $xml,
                ];
            }

            $outFlag = (string) ($this->findKey($json, ['outFlag']) ?? '');
            $errCode = (string) ($this->findKey($json, ['errCode', 'errorCode']) ?? '');
            $statusText = (string) ($this->findKey($json, [
                'operationMessage',
                'outResult',
                'errMessage',
                'shippingDeliveryExplanation',
            ]) ?? '');

            $cargoKey = (string) ($this->findKey($json, ['cargoKey']) ?? $fallbackKey);
            $docId = $this->scalarOrNull($this->findKey($json, ['docId', 'DocId', 'documentId', 'DocumentId']));
            $barcode = $this->scalarOrNull($this->findKey($json, ['barcode', 'Barcode', 'trackingNumber', 'TrackingNumber']));
            $trackingUrl = $this->scalarOrNull($this->findKey($json, ['trackingUrl', 'TrackingUrl', 'trackingURL']));

            // Halka açık takip no genelde docId / barkoddur; cargoKey referanstır.
            $publicTracking = null;
            foreach ([$docId, $barcode] as $candidate) {
                if ($candidate !== null && $candidate !== '' && strcasecmp($candidate, $cargoKey) !== 0) {
                    $publicTracking = $candidate;
                    break;
                }
            }

            // Bazı hesaplarda Yurtiçi cargoKey'i de site sorgusunda kabul eder; docId yoksa
            // yalnızca işlem kodu/mesajı anlamlıysa henüz hazır sayma.
            $operationCode = (string) ($this->findKey($json, ['operationCode', 'OperationCode']) ?? '');
            $operationStatus = strtoupper(trim((string) ($this->findKey($json, ['operationStatus', 'OperationStatus']) ?? '')));
            $jobId = $this->scalarOrNull($this->findKey($json, ['jobId', 'JobId']));
            $hasShipmentRecord = in_array($outFlag, ['0', '00'], true)
                && ($errCode === '' || $errCode === '0');

            if ($publicTracking === null && $trackingUrl) {
                if (preg_match('/[?&](?:code|barkod)=([^&]+)/i', $trackingUrl, $m)) {
                    $publicTracking = urldecode($m[1]);
                }
            }

            $trackingReady = $publicTracking !== null && $publicTracking !== '';

            if (! $trackingReady && $trackingUrl) {
                $trackingReady = true;
                $publicTracking = $publicTracking ?: $cargoKey;
            }

            if ($trackingUrl === null && $trackingReady && $publicTracking) {
                $trackingUrl = $this->buildTrackingUrl($publicTracking);
            }

            $events = $this->extractTrackingEvents($json);
            $shipmentStatus = $this->mapOperationToShipmentStatus($operationStatus, $trackingReady, $events, $statusText);

            $message = $statusText !== ''
                ? $statusText
                : ($trackingReady
                    ? 'Takip numarası hazır.'
                    : 'Gönderi kaydı var ancak halka açık takip numarası henüz oluşmamış.');

            if ($errCode !== '' && $errCode !== '0') {
                $hasShipmentRecord = false;
                $trackingReady = false;
                $message = $statusText !== '' ? $statusText : 'Kayıt bulunamadı / henüz işlenmedi.';
            }

            return [
                'success' => $hasShipmentRecord,
                'registered' => $hasShipmentRecord,
                'tracking_ready' => $trackingReady,
                'cargo_key' => $cargoKey,
                'job_id' => $jobId,
                'out_flag' => $outFlag !== '' ? $outFlag : null,
                'doc_id' => $docId,
                'barcode' => $barcode,
                'operation_code' => $operationCode !== '' ? $operationCode : null,
                'operation_status' => $operationStatus !== '' ? $operationStatus : null,
                'operation_message' => $statusText !== '' ? $statusText : null,
                'tracking_number' => $trackingReady ? $publicTracking : null,
                'tracking_url' => $trackingUrl,
                'status' => $shipmentStatus,
                'status_text' => $message,
                'message' => $message,
                'events' => $events,
                'mode' => 'api',
                'raw' => $json,
                'raw_xml' => $xml,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'tracking_ready' => false,
                'cargo_key' => $fallbackKey,
                'message' => 'Takip yanıtı parse edilemedi: '.$e->getMessage(),
                'raw_xml' => $xml,
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function mapOperationToShipmentStatus(
        string $operationStatus,
        bool $trackingReady,
        array $events,
        string $statusText
    ): string {
        $code = strtoupper(trim($operationStatus));

        if ($code === 'DLV' || $this->textIndicatesDelivered($statusText) || $this->eventsIndicateDelivered($events)) {
            return 'delivered';
        }

        if ($code === 'RTN') {
            return 'returned';
        }

        if (in_array($code, ['CNL', 'IPT'], true)) {
            return 'cancelled';
        }

        if (in_array($code, ['IND', 'UPD', 'RAS', 'MIS'], true) || $trackingReady) {
            return 'shipped';
        }

        return 'pending';
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function eventsIndicateDelivered(array $events): bool
    {
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $haystack = trim(implode(' ', [
                (string) ($event['event_code'] ?? ''),
                (string) ($event['status'] ?? ''),
                (string) ($event['title'] ?? ''),
                (string) ($event['description'] ?? ''),
            ]));

            if ($this->textIndicatesDelivered($haystack) || strtoupper($haystack) === 'DLV') {
                return true;
            }
        }

        return false;
    }

    private function textIndicatesDelivered(string $text): bool
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return false;
        }

        foreach ([
            'teslim edildi',
            'alıcıya teslim',
            'aliciya teslim',
            'teslimat gerçekleş',
            'teslimat gercekles',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function scalarOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (array_key_exists('_', $value)) {
                return $this->scalarOrNull($value['_']);
            }
            if (isset($value[0]) && ! is_array($value[0])) {
                return $this->scalarOrNull($value[0]);
            }
            if (count($value) === 1) {
                return $this->scalarOrNull(reset($value));
            }

            return null;
        }

        if (is_bool($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * Yurtiçi eventDate (YYYYMMDD) ve eventTime (HHMMSS) alanlarını birleştirir.
     * Yalnızca hareket satırının kendi alanları kullanılır; evrak/şube tarihi (documentDate)
     * iç içe çocuklardan veya gönderi başlığından alınmaz.
     *
     * @param  array<string, mixed>  $node
     */
    private function extractOccurredAt(array $node): ?string
    {
        $date = $this->scalarOrNull($this->firstLocalValue($node, [
            'eventDate', 'EventDate', 'deliveryDate', 'DeliveryDate',
            'trxDate', 'TrxDate', 'transactionDate', 'TransactionDate',
            'operationDate', 'OperationDate',
            'documentDate', 'DocumentDate',
        ]));
        $time = $this->scalarOrNull($this->firstLocalValue($node, [
            'eventTime', 'EventTime', 'deliveryTime', 'DeliveryTime',
            'trxTime', 'TrxTime', 'operationTime', 'OperationTime',
            'documentTime', 'DocumentTime', 'eventHour', 'EventHour',
            'time', 'Time',
        ]));

        return $this->combineYurticiDateAndTime($date, $time);
    }

    private function combineYurticiDateAndTime(?string $date, ?string $time): ?string
    {
        if ($date === null && $time === null) {
            return null;
        }

        $normalizedTime = $this->normalizeYurticiTime($time);

        if ($date === null) {
            return $normalizedTime;
        }

        if (preg_match('/^\d{12,13}$/', $date) === 1) {
            return date('Y-m-d H:i:s', (int) floor(((int) $date) / 1000));
        }
        if (preg_match('/^\d{10}$/', $date) === 1) {
            return date('Y-m-d H:i:s', (int) $date);
        }

        $normalizedDate = $this->normalizeYurticiDate($date);
        if ($normalizedDate === null) {
            return $normalizedTime;
        }

        $dateHasRealTime = preg_match('/\d{1,2}:\d{2}/', $normalizedDate) === 1
            && ! preg_match('/(?:T|\s)00:00(?::00)?(?:\.0+)?(?:Z|[+-]\d{2}:?\d{2})?$/', $normalizedDate);

        if ($dateHasRealTime) {
            return $normalizedDate;
        }

        if ($normalizedTime === null) {
            return $normalizedDate;
        }

        $dateOnly = preg_replace('/[T\s].*$/', '', $normalizedDate) ?? $normalizedDate;

        return $dateOnly.' '.$normalizedTime;
    }

    private function normalizeYurticiDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m) === 1) {
            return $m[1].'-'.$m[2].'-'.$m[3];
        }

        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $date, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return $date;
    }

    private function normalizeYurticiTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        $time = trim($time);
        if ($time === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m) === 1) {
            return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
        }

        $digits = preg_replace('/\D+/', '', $time) ?? '';
        $length = strlen($digits);

        if ($length === 4) {
            return substr($digits, 0, 2).':'.substr($digits, 2, 2).':00';
        }

        if ($length === 5) {
            $digits = '0'.$digits;
            $length = 6;
        }

        if ($length >= 6) {
            $digits = substr($digits, 0, 6);

            return substr($digits, 0, 2).':'.substr($digits, 2, 2).':'.substr($digits, 4, 2);
        }

        return null;
    }

    /**
     * Yurtiçi queryShipmentDetail historical / transaction listesini normalize eder.
     *
     * @param  array<string, mixed>  $json
     * @return array<int, array<string, mixed>>
     */
    private function extractTrackingEvents(array $json): array
    {
        $candidates = [];
        $this->collectEventCandidateNodes($json, $candidates);

        $events = [];
        foreach ($candidates as $node) {
            if (! is_array($node) || $this->isShipmentHeaderNode($node)) {
                continue;
            }

            $description = $this->scalarOrNull($this->firstLocalValue($node, [
                'eventName', 'EventName', 'eventDescription', 'EventDescription',
                'description', 'Description', 'statusExplanation', 'StatusExplanation',
                'unitName', 'UnitName',
            ]));
            $code = $this->scalarOrNull($this->firstLocalValue($node, [
                'eventCode', 'EventCode', 'eventId', 'EventId',
                'unitId', 'UnitId', 'statusCode', 'StatusCode',
            ]));
            $status = $this->scalarOrNull($this->firstLocalValue($node, [
                'eventStatus', 'EventStatus', 'status', 'Status',
            ]));
            $location = $this->scalarOrNull($this->firstLocalValue($node, [
                'location', 'Location', 'unitName', 'UnitName', 'branchName', 'BranchName',
                'cityName', 'CityName', 'townName', 'TownName',
            ]));
            $occurred = $this->extractOccurredAt($node);

            if ($description === null && $code === null && $status === null && $occurred === null) {
                continue;
            }

            $events[] = [
                'event_code' => $code,
                'status' => $status,
                'title' => $status ?: ($description ?: $code),
                'description' => $description ?: ($status ?: 'Kargo hareketi'),
                'location' => $location,
                'occurred_at' => $occurred,
                'raw' => $node,
            ];
        }

        $unique = [];
        foreach ($events as $event) {
            $fingerprint = md5(implode('|', [
                (string) ($event['event_code'] ?? ''),
                (string) ($event['status'] ?? ''),
                (string) ($event['description'] ?? ''),
                (string) ($event['location'] ?? ''),
                (string) ($event['occurred_at'] ?? ''),
            ]));
            $unique[$fingerprint] = $event + ['fingerprint' => $fingerprint];
        }

        return array_values($unique);
    }

    /**
     * @param  mixed  $node
     * @param  array<int, mixed>  $bag
     */
    private function collectEventCandidateNodes(mixed $node, array &$bag, int $depth = 0): void
    {
        if (! is_array($node) || $depth > 12) {
            return;
        }

        if ($this->isTrackingMovementNode($node)) {
            $bag[] = $node;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                if ($this->isListArray($child)) {
                    foreach ($child as $item) {
                        $this->collectEventCandidateNodes($item, $bag, $depth + 1);
                    }
                } else {
                    $this->collectEventCandidateNodes($child, $bag, $depth + 1);
                }
            }
        }
    }

    /**
     * Gönderi özeti (cargoKey + operationStatus + documentDate) hareket satırı değildir.
     *
     * @param  array<string, mixed>  $node
     */
    private function isShipmentHeaderNode(array $node): bool
    {
        if ($this->firstLocalValue($node, ['cargoKey', 'invoiceKey', 'InvoiceKey']) !== null) {
            return true;
        }

        foreach (array_keys($node) as $key) {
            $lower = strtolower((string) $key);
            if (str_contains($lower, 'trxlist') || str_contains($lower, 'itemdetail')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isTrackingMovementNode(array $node): bool
    {
        if (! $this->isAssocArray($node) || $this->isShipmentHeaderNode($node)) {
            return false;
        }

        $hasWhen = $this->firstLocalValue($node, [
            'eventDate', 'EventDate', 'eventTime', 'EventTime',
            'deliveryDate', 'DeliveryDate', 'trxDate', 'TrxDate',
            'transactionDate', 'TransactionDate',
        ]) !== null;

        $hasWhat = $this->firstLocalValue($node, [
            'eventName', 'EventName', 'eventId', 'EventId',
            'eventCode', 'EventCode', 'description', 'Description',
            'unitName', 'UnitName',
        ]) !== null;

        return $hasWhen && $hasWhat;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isAssocArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isListArray(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $keys
     */
    private function firstLocalValue(array $node, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $scalar = $this->scalarOrNull($value);
                if ($scalar !== null) {
                    return $scalar;
                }

                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @param  mixed  $data
     * @param  array<int, string>  $keys
     */
    private function findKey(mixed $data, array $keys): mixed
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        foreach ($data as $value) {
            $found = $this->findKey($value, $keys);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function extractXmlValue(string $xml, array $keys): ?string
    {
        try {
            $json = $this->xmlToArray($xml);
            $value = $this->findKey($json, $keys);

            return is_scalar($value) ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
