<?php

use App\Livewire\Borrowers;
use App\Livewire\Dashboard;
use App\Livewire\Loans;
use App\Livewire\Products;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::prefix('install')->controller(\App\Http\Controllers\InstallController::class)->group(function () {
    Route::get('/', 'requirements')->name('install.requirements');
    Route::get('/database', 'database')->name('install.database');
    Route::post('/database', 'saveDatabase')->name('install.database.save');
    Route::get('/admin', 'admin')->name('install.admin');
    Route::post('/admin', 'saveAdmin')->name('install.admin.save');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => view('auth.login'))->name('login');

    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::attempt($credentials, remember: true)) {
            return back()->withErrors(['email' => __('Invalid credentials.')])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    })->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::get('/', Dashboard::class)->name('dashboard');

    Route::get('/borrowers', Borrowers\Index::class)->name('borrowers.index');
    Route::get('/borrowers/create', Borrowers\Form::class)->name('borrowers.create');
    Route::get('/borrowers/{borrower}/edit', Borrowers\Form::class)->name('borrowers.edit');

    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/products', Products\Index::class)->name('products.index');
        Route::get('/products/create', Products\Form::class)->name('products.create');
        Route::get('/products/{product}/edit', Products\Form::class)->name('products.edit');
    });

    Route::get('/loans', Loans\Index::class)->name('loans.index');
    Route::get('/loans/create', Loans\Form::class)->name('loans.create');
    Route::get('/loans/{loan}', Loans\Show::class)->whereNumber('loan')->name('loans.show');

    Route::get('/loans/{loan}/statement', function (\App\Models\Loan $loan) {
        return view('loans.statement', [
            'loan' => $loan->load(['borrower', 'installments', 'payments']),
        ]);
    })->whereNumber('loan')->name('loans.statement');

    Route::get('/reports/portfolio', \App\Livewire\Reports\Portfolio::class)->name('reports.portfolio');
    Route::get('/reports/trial-balance', \App\Livewire\Reports\TrialBalance::class)->name('reports.trial-balance');
});
