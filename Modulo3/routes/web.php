<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('checkout.show');
});

Route::get('/checkout', function (Request $request) {
    return view('checkout', [
        'stripeKey' => config('modulo3.stripe.key'),
        'reservationId' => $request->query('reservation_id', 'res_778899'),
    ]);
})->name('checkout.show');
