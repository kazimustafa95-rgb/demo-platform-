<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$resolveLandingRoute = function (?User $user): \Illuminate\Http\RedirectResponse {
    if (! $user) {
        return redirect()->route('filament.admin.auth.login');
    }

    if ($user->canAccessPanel(Filament::getPanel('admin'))) {
        return redirect()->route('filament.admin.pages.dashboard');
    }

    return redirect()->route('dashboard');
};

Route::get('/', fn () => $resolveLandingRoute(Auth::user()));

Route::middleware('auth')->get('/dashboard', function () {
    /** @var User $user */
    $user = Auth::user();

    if ($user->canAccessPanel(Filament::getPanel('admin'))) {
        return redirect()->route('filament.admin.pages.dashboard');
    }

    return view('dashboard', [
        'user' => $user,
    ]);
})->name('dashboard');
