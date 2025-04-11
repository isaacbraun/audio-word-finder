<?php

use App\Http\Middleware\Subscribed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::post(
    '/stripe/webhook',
    [WebhookController::class, 'handleWebhook']
)->name('cashier.webhook');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('new', 'new')->name('new');
    Volt::route('search/{id}', 'search')->name('search');
    Volt::route('history', 'history')->name('history')->middleware([Subscribed::class]);
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('/billing', function (Request $request) {
        return $request->user()->redirectToBillingPortal(route('settings.profile'));
    })->name('billing');

    Route::get('/subscribe-basic', function (Request $request) {
        return $request->user()
            ->newSubscription('default', 'price_1RCW4qGxj0wgmRdboisPTSu1')
            ->allowPromotionCodes()
            ->checkout([
                'success_url' => route('new'),
                'cancel_url' => route('home'),
            ]);
    })->name('subscribe-basic');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
