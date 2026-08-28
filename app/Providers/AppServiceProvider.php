<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\AdminNotificationService;
use App\Services\MailConfigService;
use App\Services\OutboundIpService;
use App\Services\SidebarMenuService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\SyncActivityTracker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        try {
            $appName = Setting::appName();
            config([
                'app.name' => $appName,
                'mail.from.name' => Setting::getValue('mail_from_name') ?: $appName,
            ]);
            app(MailConfigService::class)->applyFromSettings();
        } catch (\Throwable) {
            // Settings tablosu henüz yoksa (migrate sırasında) sessiz geç.
        }

        View::composer('email.*', function ($view) {
            $data = $view->getData();
            $existing = is_array($data['brand'] ?? null) ? $data['brand'] : [];

            try {
                $defaults = app(MailConfigService::class)->branding();
            } catch (\Throwable) {
                $defaults = [
                    'name' => "O'renne",
                    'header_bg' => '#000000',
                    'header_text' => '#ffffff',
                    'body_text' => '#142433',
                    'muted_text' => '#5b6b7c',
                    'link' => '#c45c26',
                    'button_bg' => '#000000',
                    'button_text' => '#ffffff',
                    'logo_path' => null,
                    'logo_url' => null,
                    'site_url' => '',
                    'account_url' => '',
                ];
            }

            $view->with('brand', array_merge($defaults, $existing));
        });

        View::composer(['layouts.app', 'layouts.guest', 'components.sidebar'], function ($view) {
            $view->with('appBrandName', Setting::appName());
        });

        View::composer('components.sidebar', function ($view) {
            if (! Auth::check()) {
                $view->with('sidebarCounts', [
                    'open_orders' => 0,
                    'pending_shipments' => 0,
                    'unread_alerts' => 0,
                ]);

                return;
            }

            try {
                $view->with('sidebarCounts', app(SidebarMenuService::class)->counts());
            } catch (\Throwable) {
                $view->with('sidebarCounts', [
                    'open_orders' => 0,
                    'pending_shipments' => 0,
                    'unread_alerts' => 0,
                ]);
            }
        });

        View::composer('layouts.app', function ($view) {
            try {
                $outbound = app(OutboundIpService::class)->ip();
            } catch (\Throwable) {
                $outbound = null;
            }

            $view->with('serverOutboundIp', $outbound);
        });

        View::composer('components.navbar', function ($view) {
            if (! Auth::check()) {
                $view->with([
                    'adminNotificationUnread' => 0,
                    'adminNotificationLatest' => collect(),
                    'todoPendingCount' => 0,
                ]);

                return;
            }

            try {
                $service = app(AdminNotificationService::class);
                $todoPending = \App\Models\UserTodo::query()
                    ->where('user_id', Auth::id())
                    ->where('is_done', false)
                    ->count();

                $view->with([
                    'adminNotificationUnread' => $service->unreadCount(),
                    'adminNotificationLatest' => $service->latestUnread(8),
                    'todoPendingCount' => $todoPending,
                ]);
            } catch (\Throwable) {
                $view->with([
                    'adminNotificationUnread' => 0,
                    'adminNotificationLatest' => collect(),
                    'todoPendingCount' => 0,
                ]);
            }
        });
    }
}
