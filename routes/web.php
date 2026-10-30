<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/dashboard');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('tickets', 'pages::tickets.index')->name('tickets.index');
    Route::livewire('tickets/create', 'pages::tickets.create')->name('tickets.create');
    Route::livewire('tickets/{ticket}', 'pages::tickets.show')->name('tickets.show');

    Route::livewire('analytics', 'pages::analytics.index')
        ->middleware(['it.staff'])
        ->name('analytics.index');
    Route::livewire('it-accounts', 'pages::it-accounts.index')
        ->middleware(['it.staff'])
        ->name('it-accounts.index');
});

require __DIR__ . '/settings.php';
