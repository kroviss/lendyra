<?php

use App\Livewire\Borrowers;
use App\Livewire\Dashboard;
use App\Livewire\Loans;
use App\Livewire\Products;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::prefix('install')->middleware('throttle:20,1')->controller(\App\Http\Controllers\InstallController::class)->group(function () {
    Route::get('/', 'requirements')->name('install.requirements');
    Route::get('/database', 'database')->name('install.database');
    Route::post('/database', 'saveDatabase')->name('install.database.save');
    Route::get('/admin', 'admin')->name('install.admin');
    Route::post('/admin', 'saveAdmin')->name('install.admin.save');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => view('auth.login'))->name('login');

    Route::get('/forgot-password', fn () => view('auth.forgot-password'))->name('password.request');

    Route::post('/forgot-password', function (Request $request) {
        $request->validate(['email' => 'required|email']);

        \Illuminate\Support\Facades\Password::sendResetLink($request->only('email'));

        // Same response either way — never confirm whether an email exists.
        return back()->with('status', __('If that email exists, a reset link has been sent.'));
    })->middleware('throttle:5,1')->name('password.email');

    Route::get('/reset-password/{token}', fn (string $token) => view('auth.reset-password', [
        'token' => $token,
        'email' => request('email', ''),
    ]))->name('password.reset');

    Route::post('/reset-password', function (Request $request) {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = \Illuminate\Support\Facades\Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => \Illuminate\Support\Facades\Hash::make($password),
                    'remember_token' => \Illuminate\Support\Str::random(60),
                ])->save();
            }
        );

        return $status === \Illuminate\Support\Facades\Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __('Password reset — you can log in now.'))
            : back()->withErrors(['email' => __($status)]);
    })->middleware('throttle:5,1')->name('password.update');

    Route::post('/login', function (Request $request) {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (! Auth::attempt($credentials, remember: true)) {
            return back()->withErrors(['email' => __('Invalid credentials.')])->onlyInput('email');
        }

        if (! Auth::user()->is_active) {
            Auth::logout();

            return back()->withErrors(['email' => __('This account has been disabled.')])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended('/');
    })->middleware('throttle:login')->name('login.attempt');
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
    Route::get('/guarantors', \App\Livewire\Guarantors\Index::class)->name('guarantors.index');

    Route::get('/loans/{loan}/payments/{payment}/receipt', function (\App\Models\Loan $loan, int $payment) {
        $scoped = auth()->user()?->scopedBranchId();
        abort_if($scoped !== null && (int) $loan->branch_id !== $scoped, 403);

        return view('loans.receipt', [
            'loan' => $loan->load('borrower'),
            'payment' => $loan->payments()->with(['allocations.installment', 'receivedBy'])->findOrFail($payment),
        ]);
    })->whereNumber('loan')->whereNumber('payment')->name('loans.receipt');

    Route::get('/loans/{loan}/statement', function (\App\Models\Loan $loan) {
        $scoped = auth()->user()?->scopedBranchId();
        abort_if($scoped !== null && (int) $loan->branch_id !== $scoped, 403);

        $loan->load(['borrower', 'installments', 'payments']);

        $nextDue = $loan->installments->first(fn ($i) => ! $i->isSettled());

        return view('loans.statement', [
            'loan' => $loan,
            'totalPaid' => \LoanEngine\Money::minor(
                (int) $loan->payments->whereNull('reversed_at')->sum('amount_minor'),
                $loan->currency, (int) $loan->scale
            ),
            'nextDue' => $nextDue,
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
