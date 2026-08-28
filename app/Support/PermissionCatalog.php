<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class PermissionCatalog
{
    public const ACTIONS = [
        'view' => 'Listeleme / görüntüleme',
        'create' => 'Ekleme',
        'update' => 'Düzenleme',
        'delete' => 'Silme',
        'prepare' => 'Hazırlama',
    ];

    /**
     * @return array<string, array{label: string, actions: array<int, string>}>
     */
    public static function modules(): array
    {
        return [
            'orders' => ['label' => 'Siparişler', 'actions' => ['view', 'create', 'update', 'delete', 'prepare']],
            'products' => ['label' => 'Ürünler', 'actions' => ['view', 'create', 'update', 'delete']],
            'customers' => ['label' => 'Müşteriler', 'actions' => ['view', 'create', 'update', 'delete']],
            'shipments' => ['label' => 'Kargolar', 'actions' => ['view', 'create', 'update', 'delete']],
            'invoices' => ['label' => 'Faturalar', 'actions' => ['view', 'update', 'delete']],
            'alerts' => ['label' => 'Bildirimler', 'actions' => ['view', 'update', 'delete']],
            'messages' => ['label' => 'Mesaj / şablonlar', 'actions' => ['view', 'create', 'update', 'delete']],
            'reports' => ['label' => 'Raporlar', 'actions' => ['view', 'delete']],
            'sync' => ['label' => 'Senkron geçmişi', 'actions' => ['view', 'update', 'delete']],
            'settings' => ['label' => 'Ayarlar', 'actions' => ['view', 'update']],
            'users' => ['label' => 'Kullanıcılar', 'actions' => ['view', 'create', 'update', 'delete']],
            'logs' => ['label' => 'İşlem kayıtları', 'actions' => ['view', 'delete']],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allPermissionNames(): array
    {
        $names = [];
        foreach (self::modules() as $module => $meta) {
            foreach ($meta['actions'] as $action) {
                $names[] = $module.'.'.$action;
            }
            $names[] = $module.'.manage';
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            'admin' => 'Yönetici',
            'manager' => 'Müdür',
            'viewer' => 'İzleyici',
            'production' => 'Üretim personeli',
        ];
    }

    public static function roleLabel(string $name): string
    {
        return self::roleLabels()[$name] ?? ucfirst($name);
    }

    public static function allows(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('admin')) {
            return true;
        }

        if ($user->can($permission)) {
            return true;
        }

        $module = explode('.', $permission)[0] ?? '';
        if ($module !== '' && $user->can($module.'.manage')) {
            return true;
        }

        if ($user->roles()->doesntExist() && $user->getDirectPermissions()->isEmpty()) {
            return true;
        }

        return false;
    }

    public static function forRoute(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        $map = self::routeMap();
        if (isset($map[$routeName])) {
            return $map[$routeName];
        }

        foreach ($map as $pattern => $permission) {
            if (str_ends_with($pattern, '.*') && str_starts_with($routeName, substr($pattern, 0, -1))) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function routeMap(): array
    {
        return [
            'orders.index' => 'orders.view',
            'orders.show' => 'orders.view',
            'orders.archives.index' => 'orders.update',
            'orders.archives.show' => 'orders.update',
            'orders.print-label' => 'orders.view',
            'orders.sync' => 'orders.update',
            'orders.sync-one' => 'orders.update',
            'orders.uyumsoft-sync' => 'orders.update',
            'orders.update-status' => 'orders.update',
            'orders.assign-cargo' => 'orders.update',
            'orders.invoice.upload' => 'orders.update',
            'orders.invoice.destroy' => 'orders.delete',
            'orders.send-shipment-mail' => 'orders.update',
            'orders.send-invoice-mail' => 'orders.update',
            'orders.send-cargo-mail' => 'orders.update',
            'orders.sms.send' => 'orders.update',
            'orders.template-message' => 'orders.update',
            'orders.shipments.cancel' => 'orders.update',
            'orders.bulk-send-cargo' => 'orders.update',
            'orders.bulk-print-labels' => 'orders.view',
            'orders.packing.checklist' => 'orders.prepare',
            'orders.packing.status' => 'orders.prepare',
            'orders.packing.claim' => 'orders.prepare',
            'orders.packing.complete' => 'orders.prepare',
            'orders.packing.reset' => 'orders.update',
            'production.dashboard' => 'orders.prepare',
            'production.orders.index' => 'orders.prepare',
            'production.orders.show' => 'orders.prepare',
            'production.products.index' => 'products.view',
            'production.products.show' => 'products.view',

            'products.index' => 'products.view',
            'products.show' => 'products.view',
            'products.uyumsoft' => 'products.view',
            'products.shopify' => 'products.view',
            'products.shopify-mirror.show' => 'products.view',
            'products.edit' => 'products.update',
            'products.update' => 'products.update',
            'products.sync' => 'products.update',
            'products.sync-to-shopify' => 'products.update',
            'products.pull-shopify' => 'products.update',
            'products.pull-shopify-one' => 'products.update',
            'products.push-shopify' => 'products.update',
            'products.bulk' => 'products.update',
            'products.toggle-active' => 'products.update',

            'customers.index' => 'customers.view',
            'customers.show' => 'customers.view',
            'customers.sync' => 'customers.update',
            'customers.refresh' => 'customers.update',

            'shipments.index' => 'shipments.view',
            'shipments.show' => 'shipments.view',
            'shipments.create' => 'shipments.create',
            'shipments.store' => 'shipments.create',
            'shipments.sync-tracking' => 'shipments.update',
            'shipments.update-status' => 'shipments.update',
            'shipments.cancel' => 'shipments.delete',
            'shipments.generate-label' => 'shipments.update',
            'shipments.generate-invoice' => 'shipments.update',
            'shipments.print-label' => 'shipments.view',
            'shipments.print-invoice' => 'shipments.view',
            'shipments.yurtici-verify' => 'shipments.update',
            'shipments.yurtici-status' => 'shipments.update',

            'invoices.index' => 'invoices.view',

            'alerts.index' => 'alerts.view',
            'alerts.latest' => 'alerts.view',
            'alerts.read' => 'alerts.update',
            'alerts.read-all' => 'alerts.update',
            'alerts.destroy' => 'alerts.delete',
            'alerts.bulk-destroy' => 'alerts.delete',

            'messages.send' => 'messages.create',
            'messages.send.store' => 'messages.create',
            'messages.customers-search' => 'messages.view',
            'messages.customer-preview' => 'messages.view',
            'notifications.index' => 'messages.view',
            'notifications.templates' => 'messages.view',
            'notifications.templates.mail.update' => 'messages.update',
            'notifications.templates.sms.update' => 'messages.update',
            'notifications.destroy' => 'messages.delete',
            'notifications.bulk-destroy' => 'messages.delete',
            'notifications.resend' => 'messages.update',

            'reports.index' => 'reports.view',
            'reports.sales' => 'reports.view',
            'reports.sales.export.csv' => 'reports.view',
            'reports.sales.export.pdf' => 'reports.view',
            'reports.shipments' => 'reports.view',
            'reports.sync-logs' => 'reports.view',
            'reports.sync-logs.destroy' => 'reports.delete',
            'reports.sync-logs.purge-all' => 'reports.delete',
            'reports.system-logs' => 'reports.view',
            'reports.system-logs.purge-failed' => 'reports.delete',

            'sync-history.index' => 'sync.view',
            'sync-history.show' => 'sync.view',
            'sync-history.bulk-destroy' => 'sync.delete',
            'sync-history.destroy-filtered' => 'sync.delete',
            'sync-activities.show' => 'sync.view',
            'sync-activities.dismiss' => 'sync.update',
            'sync-activities.dismiss-finished' => 'sync.update',
            'sync-activities.cancel' => 'sync.update',

            'settings.index' => 'settings.view',
            'settings.general' => 'settings.view',
            'settings.general.update' => 'settings.update',
            'settings.shopify' => 'settings.view',
            'settings.shopify.update' => 'settings.update',
            'settings.shopify.test' => 'settings.update',
            'settings.shopify.reconnect' => 'settings.update',
            'settings.uyumsoft' => 'settings.view',
            'settings.uyumsoft.update' => 'settings.update',
            'settings.uyumsoft.test' => 'settings.update',
            'settings.cargo' => 'settings.view',
            'settings.cargo.update' => 'settings.update',
            'settings.cargo.test-yurtici' => 'settings.update',
            'settings.cargo.test-yurtici-shipment' => 'settings.update',
            'settings.cargo.query-yurtici-shipment' => 'settings.update',
            'settings.cargo.yurtici-label' => 'settings.view',
            'settings.mail' => 'settings.view',
            'settings.mail.update' => 'settings.update',
            'settings.mail.test' => 'settings.update',
            'settings.sms' => 'settings.view',
            'settings.sms.update' => 'settings.update',
            'settings.sms.test' => 'settings.update',
            'settings.sync' => 'settings.view',
            'settings.sync.update' => 'settings.update',
            'settings.templates.mail' => 'settings.view',
            'settings.templates.mail.update' => 'settings.update',
            'settings.templates.mail.test' => 'settings.update',
            'settings.templates.sms' => 'settings.view',
            'settings.templates.sms.update' => 'settings.update',
            'settings.templates.sms.test' => 'settings.update',

            'users.index' => 'users.view',
            'users.create' => 'users.create',
            'users.store' => 'users.create',
            'users.edit' => 'users.update',
            'users.logs' => 'users.update',
            'users.update' => 'users.update',
            'users.destroy' => 'users.delete',
            'users.deactivate' => 'users.update',
            'users.activate' => 'users.update',
            'users.reset-password' => 'users.update',

            'activity-logs.index' => 'logs.view',
            'activity-logs.show' => 'logs.view',
            'activity-logs.destroy' => 'logs.delete',

            'admin.queue.index' => 'settings.view',
            'admin.queue.*' => 'settings.update',
        ];
    }
}
