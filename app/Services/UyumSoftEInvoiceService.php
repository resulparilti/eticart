<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UyumSoftException;
use App\Models\Setting;
use SoapClient;
use SoapFault;
use SoapHeader;
use SoapVar;
use stdClass;

class UyumSoftEInvoiceService
{
    private const DEFAULT_WSDL = 'https://efatura.uyumsoft.com.tr/Services/Integration?wsdl';

    private const WSSE_NAMESPACE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    private string $username;

    private string $password;

    public function __construct()
    {
        $this->username = $this->resolveCredential(
            'uyumsoft_einvoice_user',
            'services.uyumsoft.einvoice_username',
            'uyumsoft_api_user',
            'services.uyumsoft.username'
        );
        $this->password = $this->resolveCredential(
            'uyumsoft_einvoice_password',
            'services.uyumsoft.einvoice_password',
            'uyumsoft_api_password',
            'services.uyumsoft.password'
        );
    }

    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    /**
     * True when the dedicated e-Fatura portal user is set (not the ERP fallback).
     */
    public function hasDedicatedCredentials(): bool
    {
        $username = trim((string) Setting::getValue('uyumsoft_einvoice_user', ''));
        if ($username === '') {
            $username = trim((string) config('services.uyumsoft.einvoice_username', ''));
        }

        $password = trim((string) Setting::getValue('uyumsoft_einvoice_password', ''));
        if ($password === '') {
            $password = trim((string) config('services.uyumsoft.einvoice_password', ''));
        }

        return $username !== '' && $password !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $client = $this->client();

        try {
            $response = $client->__soapCall('WhoAmI', [[]]);
        } catch (SoapFault $e) {
            throw new UyumSoftException(
                'UyumSoft e-Fatura bağlantısı başarısız: '.$e->getMessage(),
                ['method' => 'WhoAmI'],
                (int) $e->getCode(),
                $e
            );
        }

        $result = is_object($response) ? ($response->WhoAmIResult ?? null) : null;
        if (! is_object($result) || empty($result->IsSucceded)) {
            $message = is_object($result) ? trim((string) ($result->Message ?? 'WhoAmI başarısız')) : 'WhoAmI başarısız';

            throw new UyumSoftException($message);
        }

        $value = $result->Value ?? null;

        return [
            'ok' => true,
            'username' => is_object($value) ? (string) ($value->User->Username ?? $this->username) : $this->username,
            'company' => is_object($value) ? (string) ($value->Customer->Name ?? '') : '',
        ];
    }

    /**
     * @return array{content: string, extension: string, mime: string, source: string, uuid: string}
     */
    public function downloadByDocumentNumber(string $documentNumber): array
    {
        $documentNumber = trim($documentNumber);
        if ($documentNumber === '') {
            throw new UyumSoftException('Fatura belge numarası boş.');
        }

        $found = $this->findOutboxInvoice($documentNumber);
        if ($found === null) {
            throw new UyumSoftException(
                'e-Fatura giden kutusunda belge numarası bulunamadı: '.$documentNumber
            );
        }

        $invoiceId = $found['document_id'] !== '' ? $found['document_id'] : $found['invoice_id'];

        return $this->downloadOfficialDocument($invoiceId);
    }

    /**
     * @return array{invoice_id: string, document_id: string, scenario: string, order_document_id: string}|null
     */
    public function findOutboxInvoice(string $documentNumber): ?array
    {
        $client = $this->client();
        $query = [
            'PageIndex' => 0,
            'PageSize' => 10,
            'InvoiceNumbers' => [$documentNumber],
            'IncludeTagList' => false,
        ];

        foreach (['eArchive', 'eInvoice'] as $scenario) {
            try {
                $response = $client->__soapCall('GetOutboxInvoiceList', [[
                    'query' => array_merge($query, ['Scenario' => $scenario]),
                ]]);
            } catch (SoapFault) {
                continue;
            }

            $item = $this->firstListItem($response, 'GetOutboxInvoiceListResult');
            if ($item === null) {
                continue;
            }

            return [
                'invoice_id' => trim((string) ($item->InvoiceId ?? '')),
                'document_id' => trim((string) ($item->DocumentId ?? '')),
                'scenario' => (string) ($item->Scenario ?? $scenario),
                'order_document_id' => trim((string) ($item->OrderDocumentId ?? '')),
            ];
        }

        return null;
    }

    /**
     * @return array{content: string, extension: string, mime: string, source: string, uuid: string}
     */
    public function downloadOfficialDocument(string $invoiceUuid): array
    {
        $invoiceUuid = trim($invoiceUuid);
        if ($invoiceUuid === '') {
            throw new UyumSoftException('e-belge UUID boş.');
        }

        $client = $this->client();
        $lastMessage = null;

        foreach ([
            ['GetOutboxInvoicePdf', 'pdf', 'application/pdf'],
            ['GetOutboxInvoiceData', 'xml', 'application/xml'],
        ] as [$method, $extension, $mime]) {
            try {
                $response = $client->__soapCall($method, [[
                    'invoiceId' => $invoiceUuid,
                ]]);
            } catch (SoapFault $e) {
                $lastMessage = trim($e->getMessage());
                $this->throwIfUnauthorized($lastMessage, $method, $e);

                continue;
            }

            $resultName = $method.'Result';
            $result = is_object($response) ? ($response->{$resultName} ?? null) : null;
            if (is_object($result) && empty($result->IsSucceded)) {
                $lastMessage = trim((string) ($result->Message ?? $result->ErrorMessage ?? 'indirilemedi'));

                continue;
            }

            $content = $this->extractContent($response, $method);
            if ($content === null || $content === '') {
                continue;
            }

            $trimmed = ltrim($content, "\xEF\xBB\xBF \t\r\n");
            if (str_starts_with($content, '%PDF-')) {
                $extension = 'pdf';
                $mime = 'application/pdf';
            } elseif (str_starts_with($trimmed, '<?xml') || str_starts_with($trimmed, '<Invoice')) {
                $extension = 'xml';
                $mime = 'application/xml';
            }

            return [
                'content' => $content,
                'extension' => $extension,
                'mime' => $mime,
                'source' => 'Integration/'.$method,
                'uuid' => $invoiceUuid,
            ];
        }

        throw new UyumSoftException(
            'UyumSoft Integration servisinde fatura PDF/XML içeriği alınamadı. '
            .'Web servis yetkisini ve e-belge UUID değerini kontrol edin.'
            .($lastMessage ? ' Son servis hatası: '.$lastMessage : '')
        );
    }

    private function client(): SoapClient
    {
        if (! $this->isConfigured()) {
            throw new UyumSoftException('UyumSoft e-fatura kullanıcı bilgileri tanımlı değil.');
        }

        if (! class_exists(SoapClient::class)) {
            throw new UyumSoftException('PHP SOAP eklentisi etkin değil.');
        }

        $client = new SoapClient(
            (string) config('services.uyumsoft.einvoice_wsdl', self::DEFAULT_WSDL),
            [
                'cache_wsdl' => WSDL_CACHE_NONE,
                'exceptions' => true,
                'trace' => true,
                'connection_timeout' => 20,
                'keep_alive' => false,
                'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            ]
        );

        $username = new SoapVar(
            $this->username,
            XSD_STRING,
            null,
            null,
            'Username',
            self::WSSE_NAMESPACE
        );
        $password = new SoapVar(
            $this->password,
            XSD_STRING,
            null,
            null,
            'Password',
            self::WSSE_NAMESPACE
        );
        $token = new stdClass();
        $token->Username = $username;
        $token->Password = $password;

        $security = new stdClass();
        $security->UsernameToken = new SoapVar(
            $token,
            SOAP_ENC_OBJECT,
            null,
            null,
            'UsernameToken',
            self::WSSE_NAMESPACE
        );

        $client->__setSoapHeaders([
            new SoapHeader(
                self::WSSE_NAMESPACE,
                'Security',
                new SoapVar($security, SOAP_ENC_OBJECT),
                true
            ),
        ]);

        return $client;
    }

    private function firstListItem(mixed $response, string $resultName): ?object
    {
        $result = is_object($response) ? ($response->{$resultName} ?? null) : null;
        if (! is_object($result) || empty($result->IsSucceded)) {
            return null;
        }

        $items = $result->Value->Items ?? null;
        if (is_array($items) && isset($items[0]) && is_object($items[0])) {
            return $items[0];
        }
        if (is_object($items)) {
            return $items;
        }

        return null;
    }

    private function extractContent(mixed $response, string $method): ?string
    {
        $resultName = $method.'Result';
        $result = is_object($response) ? ($response->{$resultName} ?? null) : null;
        $value = is_object($result) ? ($result->Value ?? null) : null;
        $data = is_object($value) ? ($value->Data ?? null) : null;

        if (! is_string($data) || $data === '') {
            return null;
        }

        $decoded = base64_decode($data, true);

        return $decoded !== false && $decoded !== '' ? $decoded : $data;
    }

    private function throwIfUnauthorized(string $message, string $method, SoapFault $e): void
    {
        $haystack = mb_strtolower($message);
        if (! str_contains($haystack, 'yetkiniz yok') && ! str_contains($haystack, 'unauthorized')) {
            return;
        }

        throw new UyumSoftException(
            'UyumSoft e-Fatura Integration yetkisi reddedildi: '.$message,
            ['method' => $method],
            (int) $e->getCode(),
            $e
        );
    }

    private function resolveCredential(
        string $primarySetting,
        string $primaryConfig,
        string $fallbackSetting,
        string $fallbackConfig
    ): string {
        $value = trim((string) Setting::getValue($primarySetting, ''));
        if ($value !== '') {
            return $value;
        }

        $value = trim((string) config($primaryConfig, ''));
        if ($value !== '') {
            return $value;
        }

        $value = trim((string) Setting::getValue($fallbackSetting, ''));
        if ($value !== '') {
            return $value;
        }

        return trim((string) config($fallbackConfig, ''));
    }
}
