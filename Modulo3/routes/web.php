<?php

use App\Models\MockAsiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('checkout.show');
});

Route::get('/checkout', function (Request $request) {
    $asientos = MockAsiento::orderBy('seat_id')->get();

    return view('checkout', [
        'stripeKey' => config('modulo3.stripe.key'),
        'seatId' => $request->query('seat', 'A-12'),
        'asientos' => $asientos,
    ]);
})->name('checkout.show');
