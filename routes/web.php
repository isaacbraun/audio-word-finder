<?php

use App\Http\Middleware\Subscribed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Livewire\Volt\Volt;

/**
 * Home - Landing Page
 */
Volt::route('/', 'home')->name('home');

/**
 * Stripe: Webhooks
 */
Route::post(
    '/stripe/webhook',
    [WebhookController::class, 'handleWebhook']
)->name('cashier.webhook');

/**
 * Stripe: Wait for subscription to activate before forwarding to app
 */
Route::get('/checkout-success', function (Request $request) {
    $user = $request->user();

    // Wait for subscription to be active (with timeout)
    $attempts = 0;
    while ($attempts < 5) {
        $user->refresh(); // Reload user from database
        if ($user->subscribed()) {
            break;
        }
        sleep(1);
        $attempts++;
    }

    // Redirect to New Search
    return redirect()->route('new');
})->name('checkout.success');

/**
 * Volt Pages - Authenticated and Email Verified
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('new', 'new')->name('new');
    Volt::route('results/{id}', 'results')->name('results');
    Volt::route('history', 'history')->name('history')->middleware([Subscribed::class]);
});

/**
 * Settings and Billings Pages - Authenticated
 */
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
                'success_url' => route('checkout.success'),
                'cancel_url' => route('home'),
            ]);
    })->name('subscribe-basic');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';
