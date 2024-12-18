<?php

use App\Http\Controllers\DrawingController;
use App\Http\Controllers\ProfileController;
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

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Drawing routes
    Route::get('/drawing', [DrawingController::class, 'index'])->name('drawing.index');
    Route::post('/drawing/broadcast', [DrawingController::class, 'broadcast'])->name('drawing.broadcast');
    Route::post('/drawing/undo', [DrawingController::class, 'undo'])->name('drawing.undo');
    Route::post('/drawing/redo', [DrawingController::class, 'redo'])->name('drawing.redo');
    Route::get('/drawing/history', [DrawingController::class, 'getHistory'])->name('drawing.history');
});

// WebSocket Dashboard (debug only)
Route::group(['middleware' => ['auth']], function () {
    Route::get('/laravel-websockets', function () {
        return view('dashboard');
    });
});

require __DIR__.'/auth.php';
