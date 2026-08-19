<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Uygulama kodu, fatura ve görsel dosyalarının kapladığı disk alanı.
 */
class DiskUsageService
{
    private const CACHE_KEY = 'eticart.disk-usage';

    private const CACHE_SECONDS = 300;

    /**
     * @return array{
     *     software_bytes: int,
     *     invoices_bytes: int,
     *     images_bytes: int,
     *     total_bytes: int,
     *     disk_total_bytes: int,
     *     disk_free_bytes: int,
     *     software: string,
     *     invoices: string,
     *     images: string,
     *     total: string,
     *     disk_total: string,
     *     disk_free: string,
     *     used_percent: float|null
     * }
     */
    public function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->measure());
    }

    /**
     * @return array{
     *     software_bytes: int,
     *     invoices_bytes: int,
     *     images_bytes: int,
     *     total_bytes: int,
     *     disk_total_bytes: int,
     *     disk_free_bytes: int,
     *     software: string,
     *     invoices: string,
     *     images: string,
     *     total: string,
     *     disk_total: string,
     *     disk_free: string,
     *     used_percent: float|null
     * }
     */
    private function measure(): array
    {
        $invoices = $this->directorySize(storage_path('app/public/order-invoices'));
        $images = $this->directorySize(storage_path('app/public/products'));

        $skip = ['.git', 'node_modules'];
        if (app()->environment('testing')) {
            $skip[] = 'vendor';
        }

        $project = $this->directorySize(base_path(), $skip, [
            $this->normalize(storage_path('app/public/order-invoices')),
            $this->normalize(storage_path('app/public/products')),
            $this->normalize(public_path('storage')),
        ]);

        $software = max(0, $project);
        $total = $software + $invoices + $images;

        $diskTotal = 0;
        $diskFree = 0;
        try {
            $diskTotal = (int) (disk_total_space(base_path()) ?: 0);
            $diskFree = (int) (disk_free_space(base_path()) ?: 0);
        } catch (Throwable) {
        }

        $usedPercent = null;
        if ($diskTotal > 0) {
            $usedPercent = round((($diskTotal - $diskFree) / $diskTotal) * 100, 1);
        }

        return [
            'software_bytes' => $software,
            'invoices_bytes' => $invoices,
            'images_bytes' => $images,
            'total_bytes' => $total,
            'disk_total_bytes' => $diskTotal,
            'disk_free_bytes' => $diskFree,
            'software' => self::formatBytes($software),
            'invoices' => self::formatBytes($invoices),
            'images' => self::formatBytes($images),
            'total' => self::formatBytes($total),
            'disk_total' => self::formatBytes($diskTotal),
            'disk_free' => self::formatBytes($diskFree),
            'used_percent' => $usedPercent,
        ];
    }

    /**
     * @param  array<int, string>  $skipNames
     * @param  array<int, string>  $skipPaths
     */
    private function directorySize(string $path, array $skipNames = [], array $skipPaths = []): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $skipPaths = array_map(fn (string $item): string => $this->normalize($item), $skipPaths);
        $bytes = 0;

        try {
            $directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            $filter = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $current) use ($skipNames, $skipPaths): bool {
                if ($current->isLink()) {
                    return false;
                }

                $normalized = $this->normalize($current->getPathname());
                foreach ($skipPaths as $skipPath) {
                    $skipPath = rtrim($skipPath, '/');
                    if ($skipPath !== '' && ($normalized === $skipPath || str_starts_with($normalized, $skipPath.'/'))) {
                        return false;
                    }
                }

                return ! ($current->isDir() && in_array($current->getFilename(), $skipNames, true));
            });

            $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $bytes += (int) $file->getSize();
                }
            }
        } catch (Throwable) {
            return $bytes;
        }

        return $bytes;
    }

    private function normalize(string $path): string
    {
        $resolved = realpath($path);

        return str_replace('\\', '/', $resolved !== false ? $resolved : $path);
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unit = 'B';
        foreach ($units as $candidate) {
            $value /= 1024;
            $unit = $candidate;
            if ($value < 1024) {
                break;
            }
        }

        return number_format($value, $value >= 10 || $unit === 'KB' ? 0 : 1, ',', '.').' '.$unit;
    }
}
