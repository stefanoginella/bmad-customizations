<?php

use App\Connector\Contract;
use App\Http\Controllers\Connector\PhoneHomeController;
use Illuminate\Support\Facades\Route;

// No `throttle`: AD-7 makes any 4xx permanent-quiet until the next daily slot,
// so a 429 caused by a shared client IP would silence an honest site for a day.
Route::prefix(Contract::routePrefix())->group(function (): void {
    Route::post('phone-home', PhoneHomeController::class)
        ->middleware('auth:connector')
        ->name('connector.phone-home');
});
