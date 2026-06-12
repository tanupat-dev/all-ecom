<?php

use App\Http\Controllers\Pos\ReceiptController;
use App\Livewire\PosTerminal;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/pos', PosTerminal::class)->name('pos.terminal');
    Route::get('/pos/receipt/{order}', ReceiptController::class)->name('pos.receipt');
});
