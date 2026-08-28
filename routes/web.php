<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\OrderCalendarController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderPackingController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QueueMonitorController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShopifyAppController;
use App\Http\Controllers\SyncActivityController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserNoteController;
use App\Http\Controllers\UserTodoController;
use App\Support\PanelNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::match(['get', 'head'], '/', function (Request $request) {
    if ($request->filled('shop') || $request->filled('hmac') || $request->filled('host')) {
        return app(ShopifyAppController::class)->entry($request);
    }

    if ($request->user()) {
        return PanelNavigation::replaceResponse($request);
    }

    return redirect('/login');
});
Route::get('/session/status', function (Request $request) {
    $authenticated = $request->user() !== null;

    return response()->json([
        'authenticated' => $authenticated,
        'last' => $authenticated ? PanelNavigation::last($request) : null,
    ]);
})->name('session.status');
Route::post('/', [ShopifyAppController::class, 'entry']);
Route::get('/fatura/{token}', [PublicInvoiceController::class, 'show'])
    ->name('invoices.public')
    ->where('token', '[A-Fa-f0-9]{48}');
Route::get('/f/{token}', [PublicInvoiceController::class, 'show'])
    ->where('token', '[A-Fa-f0-9]{48}');

Route::prefix('shopify')->name('shopify.')->group(function () {
    Route::match(['get', 'post'], '/', [ShopifyAppController::class, 'entry'])->name('app');
    Route::match(['get', 'post'], '/install', [ShopifyAppController::class, 'install'])->name('install');
    Route::match(['get', 'post'], '/callback', [ShopifyAppController::class, 'callback'])->name('callback');
    Route::match(['get', 'post'], '/auth/callback', [ShopifyAppController::class, 'callback'])->name('auth.callback');
    Route::get('/health', [ShopifyAppController::class, 'health'])->name('health');
    Route::post('/webhooks/customers-data-request', [ShopifyAppController::class, 'customersDataRequest'])->name('webhooks.customers-data-request');
    Route::post('/webhooks/customers-redact', [ShopifyAppController::class, 'customersRedact'])->name('webhooks.customers-redact');
    Route::post('/webhooks/shop-redact', [ShopifyAppController::class, 'shopRedact'])->name('webhooks.shop-redact');
});

Route::middleware(['auth', 'verified', 'active', 'module'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/search', GlobalSearchController::class)->name('search.global');

    Route::get('/todos', [UserTodoController::class, 'index'])->name('todos.index');
    Route::post('/todos', [UserTodoController::class, 'store'])->name('todos.store');
    Route::put('/todos/{todo}', [UserTodoController::class, 'update'])->name('todos.update');
    Route::post('/todos/{todo}/toggle', [UserTodoController::class, 'toggle'])->name('todos.toggle');
    Route::delete('/todos/{todo}', [UserTodoController::class, 'destroy'])->name('todos.destroy');

    Route::get('/notes', [UserNoteController::class, 'index'])->name('notes.index');
    Route::post('/notes', [UserNoteController::class, 'store'])->name('notes.store');
    Route::put('/notes/{note}', [UserNoteController::class, 'update'])->name('notes.update');
    Route::post('/notes/{note}/archive', [UserNoteController::class, 'archive'])->name('notes.archive');
    Route::post('/notes/{note}/restore', [UserNoteController::class, 'restore'])->name('notes.restore');
    Route::delete('/notes/{note}', [UserNoteController::class, 'destroy'])->name('notes.destroy');

    Route::get('/kanban', [KanbanController::class, 'index'])->name('kanban.index');
    Route::post('/kanban/columns', [KanbanController::class, 'storeColumn'])->name('kanban.columns.store');
    Route::put('/kanban/columns/{column}', [KanbanController::class, 'updateColumn'])->name('kanban.columns.update');
    Route::delete('/kanban/columns/{column}', [KanbanController::class, 'destroyColumn'])->name('kanban.columns.destroy');
    Route::post('/kanban/cards', [KanbanController::class, 'storeCard'])->name('kanban.cards.store');
    Route::put('/kanban/cards/{card}', [KanbanController::class, 'updateCard'])->name('kanban.cards.update');
    Route::delete('/kanban/cards/{card}', [KanbanController::class, 'destroyCard'])->name('kanban.cards.destroy');
    Route::post('/kanban/cards/move', [KanbanController::class, 'moveCard'])->name('kanban.cards.move');

    Route::get('/calendar', [OrderCalendarController::class, 'index'])->name('calendar.index');
    Route::get('/calendar/events', [OrderCalendarController::class, 'events'])->name('calendar.events');

    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');
        Route::post('/{user}/deactivate', [UserController::class, 'deactivate'])->name('deactivate');
        Route::post('/{user}/activate', [UserController::class, 'activate'])->name('activate');
        Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->name('reset-password');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
    });

    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');

    Route::middleware('role:admin')->prefix('admin/queue')->name('admin.queue.')->group(function () {
        Route::get('/', [QueueMonitorController::class, 'index'])->name('index');
        Route::post('/retry-all', [QueueMonitorController::class, 'retryAll'])->name('retry-all');
        Route::post('/flush', [QueueMonitorController::class, 'flushFailed'])->name('flush');
        Route::post('/clear-pending', [QueueMonitorController::class, 'clearPending'])->name('clear-pending');
        Route::post('/process-now', [QueueMonitorController::class, 'processNow'])->name('process-now');
        Route::post('/dispatch-test', [QueueMonitorController::class, 'dispatchTest'])->name('dispatch-test');
        Route::post('/{uuid}/retry', [QueueMonitorController::class, 'retry'])->name('retry');
    });

    Route::get('/sync-activities/live', [SyncActivityController::class, 'live'])
        ->name('sync-activities.live');
    Route::post('/sync-activities/dismiss-finished', [SyncActivityController::class, 'dismissFinished'])
        ->name('sync-activities.dismiss-finished');
    Route::post('/sync-activities/{uuid}/dismiss', [SyncActivityController::class, 'dismiss'])
        ->name('sync-activities.dismiss');
    Route::post('/sync-activities/{uuid}/cancel', [SyncActivityController::class, 'cancel'])
        ->name('sync-activities.cancel');
    Route::get('/sync-activities/{uuid}', [SyncActivityController::class, 'show'])
        ->name('sync-activities.show');

    Route::get('/sync-history', [SyncActivityController::class, 'history'])
        ->name('sync-history.index');
    Route::post('/sync-history/bulk-delete', [SyncActivityController::class, 'bulkDestroy'])
        ->name('sync-history.bulk-destroy');
    Route::post('/sync-history/delete-filtered', [SyncActivityController::class, 'destroyFiltered'])
        ->name('sync-history.destroy-filtered');
    Route::get('/sync-history/{uuid}', [SyncActivityController::class, 'historyShow'])
        ->name('sync-history.show');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers/sync', [CustomerController::class, 'sync'])->name('customers.sync');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::post('/customers/{customer}/refresh', [CustomerController::class, 'refresh'])->name('customers.refresh');

    Route::get('/messages/send', [MessageController::class, 'create'])->name('messages.send');
    Route::post('/messages/send', [MessageController::class, 'store'])->name('messages.send.store');
    Route::get('/messages/customers-search', [MessageController::class, 'customersSearch'])->name('messages.customers-search');
    Route::get('/messages/customers/{customer}/preview', [MessageController::class, 'customerPreview'])->name('messages.customer-preview');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('/orders/sync', [OrderController::class, 'sync'])->name('orders.sync');
    Route::get('/orders/archives', [OrderController::class, 'archives'])->name('orders.archives.index');
    Route::get('/orders/archives/{archive}', [OrderController::class, 'showArchive'])->name('orders.archives.show');
    Route::post('/orders/bulk-send-cargo', [OrderController::class, 'bulkSendCargo'])->name('orders.bulk-send-cargo');
    Route::post('/orders/bulk-print-labels', [OrderController::class, 'bulkPrintLabels'])->name('orders.bulk-print-labels');
    Route::get('/orders/{order}/print-label', [OrderController::class, 'printLabel'])->name('orders.print-label');
    Route::post('/orders/{order}/sync', [OrderController::class, 'syncOne'])->name('orders.sync-one');
    Route::match(['get', 'post'], '/orders/{order}/uyumsoft-sync', [OrderController::class, 'syncUyumsoft'])->name('orders.uyumsoft-sync');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/packing/checklist', [OrderPackingController::class, 'updateChecklist'])->name('orders.packing.checklist');
    Route::post('/orders/{order}/packing/complete', [OrderPackingController::class, 'complete'])->name('orders.packing.complete');
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
    Route::post('/orders/{order}/assign-cargo', [OrderController::class, 'assignCargo'])->name('orders.assign-cargo');
    Route::post('/orders/{order}/invoice', [OrderController::class, 'uploadInvoice'])->name('orders.invoice.upload');
    Route::delete('/orders/{order}/invoice', [OrderController::class, 'destroyInvoice'])->name('orders.invoice.destroy');
    Route::match(['get', 'post'], '/orders/{order}/send-shipment-mail', [OrderController::class, 'sendShipmentInvoiceMail'])->name('orders.send-shipment-mail');
    Route::post('/orders/{order}/send-invoice-mail', [OrderController::class, 'sendInvoiceNoticeMail'])->name('orders.send-invoice-mail');
    Route::post('/orders/{order}/send-cargo-mail', [OrderController::class, 'sendCargoNoticeMail'])->name('orders.send-cargo-mail');
    Route::post('/orders/{order}/sms', [OrderController::class, 'sendSms'])->name('orders.sms.send');
    Route::post('/orders/{order}/template-message', [OrderController::class, 'sendTemplateMessage'])->name('orders.template-message');
    Route::post('/orders/{order}/shipments/{shipment}/cancel', [OrderController::class, 'cancelShipment'])->name('orders.shipments.cancel');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/uyumsoft', [ProductController::class, 'uyumSoftList'])->name('products.uyumsoft');
    Route::get('/products/shopify', [ProductController::class, 'shopifyList'])->name('products.shopify');
    Route::match(['get', 'post'], '/products/sync-to-shopify', [ProductController::class, 'syncToShopify'])->name('products.sync-to-shopify');
    Route::post('/products/sync', [ProductController::class, 'sync'])->name('products.sync');
    Route::post('/products/pull-shopify', [ProductController::class, 'pullFromShopify'])->name('products.pull-shopify');
    Route::get('/products/shopify-mirror/{shopifyProduct}', [ProductController::class, 'showShopifyMirror'])->name('products.shopify-mirror.show');
    Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('/products/{product}/toggle-active', [ProductController::class, 'toggleActive'])->name('products.toggle-active');
    Route::post('/products/{product}/push-shopify', [ProductController::class, 'pushShopify'])->name('products.push-shopify');
    Route::post('/products/{product}/pull-shopify', [ProductController::class, 'pullShopifyProduct'])->name('products.pull-shopify-one');

    Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
    Route::post('/shipments/sync-tracking', [ShipmentController::class, 'syncTracking'])->name('shipments.sync-tracking');
    Route::get('/orders/{order}/shipments/create', [ShipmentController::class, 'create'])->name('shipments.create');
    Route::post('/orders/{order}/shipments', [ShipmentController::class, 'store'])->name('shipments.store');
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
    Route::post('/shipments/{shipment}/yurtici-verify', [ShipmentController::class, 'queryYurticiVerify'])->name('shipments.yurtici-verify');
    Route::post('/shipments/{shipment}/yurtici-status', [ShipmentController::class, 'queryYurticiStatus'])->name('shipments.yurtici-status');
    Route::post('/shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])->name('shipments.cancel');
    Route::patch('/shipments/{shipment}/status', [ShipmentController::class, 'updateStatus'])->name('shipments.update-status');
    Route::post('/shipments/{shipment}/generate-label', [ShipmentController::class, 'generateLabel'])->name('shipments.generate-label');
    Route::post('/shipments/{shipment}/generate-invoice', [ShipmentController::class, 'generateInvoice'])->name('shipments.generate-invoice');
    Route::get('/shipments/{shipment}/print-label', [ShipmentController::class, 'printLabel'])->name('shipments.print-label');
    Route::get('/shipments/{shipment}/print-invoice', [ShipmentController::class, 'printInvoice'])->name('shipments.print-invoice');

    Route::get('/alerts', [AdminNotificationController::class, 'index'])->name('alerts.index');
    Route::get('/alerts/latest', [AdminNotificationController::class, 'latest'])->name('alerts.latest');
    Route::post('/alerts/read-all', [AdminNotificationController::class, 'markAllRead'])->name('alerts.read-all');
    Route::post('/alerts/bulk-destroy', [AdminNotificationController::class, 'bulkDestroy'])->name('alerts.bulk-destroy');
    Route::delete('/alerts/{alert}', [AdminNotificationController::class, 'destroy'])->name('alerts.destroy');
    Route::get('/alerts/{alert}/read', [AdminNotificationController::class, 'markRead'])->name('alerts.read');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/templates', [NotificationController::class, 'templates'])->name('notifications.templates');
    Route::put('/notifications/templates/mail/{template}', [NotificationController::class, 'updateMailTemplate'])->name('notifications.templates.mail.update');
    Route::put('/notifications/templates/sms/{template}', [NotificationController::class, 'updateSmsTemplate'])->name('notifications.templates.sms.update');
    Route::post('/notifications/bulk-destroy', [NotificationController::class, 'bulkDestroy'])->name('notifications.bulk-destroy');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::match(['get', 'post'], '/notifications/{notification}/resend', [NotificationController::class, 'resend'])->name('notifications.resend');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/general', [SettingsController::class, 'general'])->name('settings.general');
    Route::put('/settings/general', [SettingsController::class, 'updateGeneral'])->name('settings.general.update');
    Route::get('/settings/shopify', [SettingsController::class, 'shopify'])->name('settings.shopify');
    Route::put('/settings/shopify', [SettingsController::class, 'updateShopify'])->name('settings.shopify.update');
    Route::post('/settings/shopify/test', [SettingsController::class, 'testShopify'])->name('settings.shopify.test');
    Route::post('/settings/shopify/reconnect', [SettingsController::class, 'reconnectShopify'])->name('settings.shopify.reconnect');
    Route::get('/settings/uyumsoft', [SettingsController::class, 'uyumsoft'])->name('settings.uyumsoft');
    Route::put('/settings/uyumsoft', [SettingsController::class, 'updateUyumsoft'])->name('settings.uyumsoft.update');
    Route::post('/settings/uyumsoft/test', [SettingsController::class, 'testUyumsoft'])->name('settings.uyumsoft.test');
    Route::get('/settings/cargo', [SettingsController::class, 'cargo'])->name('settings.cargo');
    Route::match(['put', 'post'], '/settings/cargo', [SettingsController::class, 'updateCargo'])->name('settings.cargo.update');
    Route::post('/settings/cargo/test-yurtici', [SettingsController::class, 'testYurtici'])->name('settings.cargo.test-yurtici');
    Route::post('/settings/cargo/test-yurtici-shipment', [SettingsController::class, 'testYurticiShipment'])->name('settings.cargo.test-yurtici-shipment');
    Route::post('/settings/cargo/query-yurtici-shipment', [SettingsController::class, 'queryYurticiShipment'])->name('settings.cargo.query-yurtici-shipment');
    Route::get('/settings/cargo/yurtici-label', [SettingsController::class, 'printYurticiLabel'])->name('settings.cargo.yurtici-label');
    Route::get('/settings/mail', [SettingsController::class, 'mail'])->name('settings.mail');
    Route::put('/settings/mail', [SettingsController::class, 'updateMail'])->name('settings.mail.update');
    Route::post('/settings/mail/test', [SettingsController::class, 'testMail'])->name('settings.mail.test');
    Route::get('/settings/sms', [SettingsController::class, 'sms'])->name('settings.sms');
    Route::put('/settings/sms', [SettingsController::class, 'updateSms'])->name('settings.sms.update');
    Route::post('/settings/sms/test', [SettingsController::class, 'testSms'])->name('settings.sms.test');
    Route::get('/settings/sync', [SettingsController::class, 'sync'])->name('settings.sync');
    Route::put('/settings/sync', [SettingsController::class, 'updateSync'])->name('settings.sync.update');
    Route::get('/settings/templates/mail', [SettingsController::class, 'mailTemplates'])->name('settings.templates.mail');
    Route::put('/settings/templates/mail/{template}', [SettingsController::class, 'updateMailTemplate'])->name('settings.templates.mail.update');
    Route::post('/settings/templates/mail/{template}/test', [SettingsController::class, 'testMailTemplate'])->name('settings.templates.mail.test');
    Route::get('/settings/templates/sms', [SettingsController::class, 'smsTemplates'])->name('settings.templates.sms');
    Route::put('/settings/templates/sms/{template}', [SettingsController::class, 'updateSmsTemplate'])->name('settings.templates.sms.update');
    Route::post('/settings/templates/sms/{template}/test', [SettingsController::class, 'testSmsTemplate'])->name('settings.templates.sms.test');

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/sales', [ReportController::class, 'sales'])->name('sales');
        Route::get('/sales/export/csv', [ReportController::class, 'exportSalesCsv'])->name('sales.export.csv');
        Route::get('/sales/export/pdf', [ReportController::class, 'exportSalesPdf'])->name('sales.export.pdf');
        Route::get('/shipments', [ReportController::class, 'shipments'])->name('shipments');
        Route::get('/sync-logs', [ReportController::class, 'syncLogs'])->name('sync-logs');
        Route::post('/sync-logs/bulk-delete', [ReportController::class, 'destroySyncLogs'])->name('sync-logs.destroy');
        Route::post('/sync-logs/purge-all', [ReportController::class, 'purgeAllSyncLogs'])->name('sync-logs.purge-all');
        Route::get('/system-logs', [ReportController::class, 'systemLogs'])->name('system-logs');
        Route::post('/system-logs/purge-failed', [ReportController::class, 'purgeFailedSyncLogs'])->name('system-logs.purge-failed');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
