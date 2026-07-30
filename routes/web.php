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
    })->middleware('throttle:5,1')->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/profile', \App\Livewire\Profile::class)->name('profile');

    Route::get('/borrowers', Borrowers\Index::class)->name('borrowers.index');
    Route::get('/borrowers/create', Borrowers\Form::class)->middleware('role:admin,manager,loan_officer')->name('borrowers.create');
    Route::get('/borrowers/{borrower}/edit', Borrowers\Form::class)->middleware('role:admin,manager,loan_officer')->name('borrowers.edit');
    Route::get('/borrowers/{borrower}', Borrowers\Show::class)->whereNumber('borrower')->name('borrowers.show');

    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/products', Products\Index::class)->name('products.index');
        Route::get('/products/create', Products\Form::class)->name('products.create');
        Route::get('/products/{product}/edit', Products\Form::class)->name('products.edit');

        Route::get('/branches', \App\Livewire\Branches\Index::class)->name('branches.index');
        Route::get('/branches/create', \App\Livewire\Branches\Form::class)->name('branches.create');
        Route::get('/branches/{branch}/edit', \App\Livewire\Branches\Form::class)->name('branches.edit');
    });

    Route::get('/loans', Loans\Index::class)->name('loans.index');
    Route::get('/loans/create', Loans\Form::class)->middleware('role:admin,manager,loan_officer')->name('loans.create');
    Route::get('/loans/{loan}/edit', Loans\Form::class)->whereNumber('loan')->middleware('role:admin,manager,loan_officer')->name('loans.edit');
    Route::get('/loans/{loan}', Loans\Show::class)->whereNumber('loan')->name('loans.show');
    Route::get('/payments', \App\Livewire\Payments\Index::class)->name('payments.index');

    Route::get('/collaterals', \App\Livewire\Collaterals\Index::class)->name('collaterals.index');

    Route::get('/loans/{loan}/payments/{payment}/receipt', function (\App\Models\Loan $loan, int $payment) {
        return view('loans.receipt', [
            'loan' => $loan->load('borrower'),
            'payment' => $loan->payments()->with(['allocations.installment', 'receivedBy'])->findOrFail($payment),
        ]);
    })->whereNumber('loan')->whereNumber('payment')->name('loans.receipt');

    Route::get('/loans/{loan}/statement', function (\App\Models\Loan $loan) {
        return view('loans.statement', [
            'loan' => $loan->load(['borrower', 'installments', 'payments']),
        ]);
    })->whereNumber('loan')->name('loans.statement');

    Route::middleware('role:admin')->group(function () {
        Route::get('/users', \App\Livewire\Users\Index::class)->name('users.index');
        Route::get('/users/create', \App\Livewire\Users\Form::class)->name('users.create');
        Route::get('/users/{user}/edit', \App\Livewire\Users\Form::class)->name('users.edit');
    });

    Route::get('/reports/portfolio', \App\Livewire\Reports\Portfolio::class)->middleware('role:admin,manager,accountant')->name('reports.portfolio');
    Route::get('/reports/collections', \App\Livewire\Reports\Collections::class)->name('reports.collections');
    Route::get('/reports/trial-balance', \App\Livewire\Reports\TrialBalance::class)->middleware('role:admin,manager,accountant')->name('reports.trial-balance');
    Route::get('/sms-logs', \App\Livewire\SmsLogs\Index::class)->middleware('role:admin,manager')->name('sms-logs.index');
});
