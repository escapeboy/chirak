<?php

use App\Http\Controllers\RunController;
use App\Livewire\CreateRun;
use App\Livewire\OwnTickets;
use App\Livewire\Playground;
use App\Livewire\RunDashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', [RunController::class, 'index'])->name('home');
Route::post('/unlock', [RunController::class, 'unlock'])->middleware('throttle:10,1')->name('unlock');

Route::get('/runs/new', CreateRun::class)->name('runs.create');
Route::get('/runs/{run}', RunDashboard::class)->name('runs.show');
Route::get('/runs/{run}/playground', Playground::class)->name('runs.playground');
Route::get('/runs/{run}/tickets', OwnTickets::class)->name('runs.tickets');
Route::get('/runs/{run}/adapters/{iteration}', [RunController::class, 'adapter'])->whereNumber('iteration')->name('runs.adapter');
Route::get('/r/{run}', [RunController::class, 'report'])->name('runs.report');
