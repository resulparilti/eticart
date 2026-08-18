<?php

declare(strict_types=1);

namespace App\Support;

final class TurkeyLocality
{
    /**
     * @var array<string, string> normalized key => canonical il adı
     */
    private static array $provinces = [];

    /**
     * @var array<string, string> plaka / TR-xx => il adı
     */
    private static array $plates = [];

    public static function isProvince(string $value): bool
    {
        return self::canonicalProvince($value) !== null;
    }

    public static function isPostalCode(string $value): bool
    {
        return (bool) preg_match('/^\d{4,5}$/', trim($value));
    }

    public static function isCountry(string $value): bool
    {
        $key = self::normalize($value);

        return in_array($key, ['TURKEY', 'TURKIYE', 'TR'], true);
    }

    /**
     * "Bursa", "BURSA", "tr-16", "16" → "Bursa"
     */
    public static function canonicalProvince(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        self::boot();

        $key = self::normalize($value);
        if (isset(self::$provinces[$key])) {
            return self::$provinces[$key];
        }

        if (preg_match('/^(?:TR[-\s]?)?(\d{1,2})$/i', $value, $m)) {
            $plate = str_pad($m[1], 2, '0', STR_PAD_LEFT);

            return self::$plates[$plate] ?? null;
        }

        return null;
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        $map = [
            'İ' => 'I', 'I' => 'I', 'ı' => 'I', 'i' => 'I',
            'Ş' => 'S', 'ş' => 'S',
            'Ğ' => 'G', 'ğ' => 'G',
            'Ü' => 'U', 'ü' => 'U',
            'Ö' => 'O', 'ö' => 'O',
            'Ç' => 'C', 'ç' => 'C',
        ];
        $value = strtr($value, $map);

        return strtoupper($value);
    }

    /**
     * Metnin içinde geçen ilk il adını bulur (Bursa, İstanbul…).
     */
    public static function findProvinceInText(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        self::boot();

        $normalized = ' '.self::normalize($text).' ';
        $names = [];
        foreach (self::$provinces as $key => $name) {
            if (ctype_digit((string) $key)) {
                continue;
            }
            $names[$name] = mb_strlen($name);
        }
        arsort($names);

        foreach (array_keys($names) as $name) {
            $needle = ' '.self::normalize((string) $name).' ';
            if (str_contains($normalized, $needle)) {
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * Bilinen ilçe → il (Shopify'da yalnızca ilçe yazıldığında).
     */
    public static function provinceForDistrict(?string $district): ?string
    {
        $district = trim((string) $district);
        if ($district === '') {
            return null;
        }

        $key = self::normalize($district);
        $map = [
            'MUDANYA' => 'Bursa',
            'OSMANGAZI' => 'Bursa',
            'NILUFER' => 'Bursa',
            'YILDIRIM' => 'Bursa',
            'GEMLIK' => 'Bursa',
            'INEGOL' => 'Bursa',
            'IZNIK' => 'Bursa',
            'KARACABEY' => 'Bursa',
            'KELES' => 'Bursa',
            'KESTEL' => 'Bursa',
            'ORHANELI' => 'Bursa',
            'ORHANGAZI' => 'Bursa',
            'YENISEHIR' => 'Bursa',
            'BUYUKORHAN' => 'Bursa',
            'HARMANCIK' => 'Bursa',
            'GURSU' => 'Bursa',
            'MUSTAFAKEMALPASA' => 'Bursa',
            'KADIKOY' => 'İstanbul',
            'USKUDAR' => 'İstanbul',
            'BESIKTAS' => 'İstanbul',
            'SISLI' => 'İstanbul',
            'FATIH' => 'İstanbul',
            'UMRANIYE' => 'İstanbul',
            'ATASEHIR' => 'İstanbul',
            'MALTEPE' => 'İstanbul',
            'PENDIK' => 'İstanbul',
            'KARTAL' => 'İstanbul',
            'BAKIRKOY' => 'İstanbul',
            'BAGCILAR' => 'İstanbul',
            'KUCUKCEKMECE' => 'İstanbul',
            'BUYUKCEKMECE' => 'İstanbul',
            'SARIYER' => 'İstanbul',
            'BEYKOZ' => 'İstanbul',
            'KONAK' => 'İzmir',
            'BORNOVA' => 'İzmir',
            'KARSIYAKA' => 'İzmir',
            'BUCA' => 'İzmir',
            'CANKAYA' => 'Ankara',
            'KECIOREN' => 'Ankara',
            'YENIMAHALLE' => 'Ankara',
            'MAMAK' => 'Ankara',
            'ETIMESGUT' => 'Ankara',
        ];

        return $map[$key] ?? null;
    }

    private static function boot(): void
    {
        if (self::$provinces !== []) {
            return;
        }

        $names = [
            'Adana', 'Adıyaman', 'Afyonkarahisar', 'Ağrı', 'Amasya', 'Ankara', 'Antalya', 'Artvin',
            'Aydın', 'Balıkesir', 'Bilecik', 'Bingöl', 'Bitlis', 'Bolu', 'Burdur', 'Bursa',
            'Çanakkale', 'Çankırı', 'Çorum', 'Denizli', 'Diyarbakır', 'Edirne', 'Elazığ', 'Erzincan',
            'Erzurum', 'Eskişehir', 'Gaziantep', 'Giresun', 'Gümüşhane', 'Hakkari', 'Hatay', 'Isparta',
            'Mersin', 'İstanbul', 'İzmir', 'Kars', 'Kastamonu', 'Kayseri', 'Kırklareli', 'Kırşehir',
            'Kocaeli', 'Konya', 'Kütahya', 'Malatya', 'Manisa', 'Kahramanmaraş', 'Mardin', 'Muğla',
            'Muş', 'Nevşehir', 'Niğde', 'Ordu', 'Rize', 'Sakarya', 'Samsun', 'Siirt',
            'Sinop', 'Sivas', 'Tekirdağ', 'Tokat', 'Trabzon', 'Tunceli', 'Şanlıurfa', 'Uşak',
            'Van', 'Yozgat', 'Zonguldak', 'Aksaray', 'Bayburt', 'Karaman', 'Kırıkkale', 'Batman',
            'Şırnak', 'Bartın', 'Ardahan', 'Iğdır', 'Yalova', 'Karabük', 'Kilis', 'Osmaniye', 'Düzce',
        ];

        foreach ($names as $name) {
            self::$provinces[self::normalize($name)] = $name;
        }

        // Sık görülen alternatif yazımlar
        self::$provinces[self::normalize('Istanbul')] = 'İstanbul';
        self::$provinces[self::normalize('Izmir')] = 'İzmir';
        self::$provinces[self::normalize('Afyon')] = 'Afyonkarahisar';
        self::$provinces[self::normalize('Maras')] = 'Kahramanmaraş';
        self::$provinces[self::normalize('Kahramanmaras')] = 'Kahramanmaraş';
        self::$provinces[self::normalize('Urfa')] = 'Şanlıurfa';
        self::$provinces[self::normalize('Sanliurfa')] = 'Şanlıurfa';
        self::$provinces[self::normalize('Icme')] = 'Mersin';
        self::$provinces[self::normalize('Icel')] = 'Mersin';

        $plates = [
            '01' => 'Adana', '02' => 'Adıyaman', '03' => 'Afyonkarahisar', '04' => 'Ağrı', '05' => 'Amasya',
            '06' => 'Ankara', '07' => 'Antalya', '08' => 'Artvin', '09' => 'Aydın', '10' => 'Balıkesir',
            '11' => 'Bilecik', '12' => 'Bingöl', '13' => 'Bitlis', '14' => 'Bolu', '15' => 'Burdur',
            '16' => 'Bursa', '17' => 'Çanakkale', '18' => 'Çankırı', '19' => 'Çorum', '20' => 'Denizli',
            '21' => 'Diyarbakır', '22' => 'Edirne', '23' => 'Elazığ', '24' => 'Erzincan', '25' => 'Erzurum',
            '26' => 'Eskişehir', '27' => 'Gaziantep', '28' => 'Giresun', '29' => 'Gümüşhane', '30' => 'Hakkari',
            '31' => 'Hatay', '32' => 'Isparta', '33' => 'Mersin', '34' => 'İstanbul', '35' => 'İzmir',
            '36' => 'Kars', '37' => 'Kastamonu', '38' => 'Kayseri', '39' => 'Kırklareli', '40' => 'Kırşehir',
            '41' => 'Kocaeli', '42' => 'Konya', '43' => 'Kütahya', '44' => 'Malatya', '45' => 'Manisa',
            '46' => 'Kahramanmaraş', '47' => 'Mardin', '48' => 'Muğla', '49' => 'Muş', '50' => 'Nevşehir',
            '51' => 'Niğde', '52' => 'Ordu', '53' => 'Rize', '54' => 'Sakarya', '55' => 'Samsun',
            '56' => 'Siirt', '57' => 'Sinop', '58' => 'Sivas', '59' => 'Tekirdağ', '60' => 'Tokat',
            '61' => 'Trabzon', '62' => 'Tunceli', '63' => 'Şanlıurfa', '64' => 'Uşak', '65' => 'Van',
            '66' => 'Yozgat', '67' => 'Zonguldak', '68' => 'Aksaray', '69' => 'Bayburt', '70' => 'Karaman',
            '71' => 'Kırıkkale', '72' => 'Batman', '73' => 'Şırnak', '74' => 'Bartın', '75' => 'Ardahan',
            '76' => 'Iğdır', '77' => 'Yalova', '78' => 'Karabük', '79' => 'Kilis', '80' => 'Osmaniye',
            '81' => 'Düzce',
        ];
        self::$plates = $plates;
        foreach ($plates as $plate => $name) {
            self::$provinces[$plate] = $name;
        }
    }
}
