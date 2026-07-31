<?php

namespace App\Livewire\Users;

use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?User $user = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'loan_officer';

    public ?int $branch_id = null;

    public bool $is_active = true;

    public const ROLES = ['admin', 'manager', 'loan_officer', 'cashier', 'accountant'];

    public function mount(User|int|null $user = null): void
    {
        if ($user !== null) {
            $this->user = $user instanceof User ? $user : User::findOrFail($user);

            $this->name = $this->user->name;
            $this->email = $this->user->email;
            $this->role = $this->user->role;
            $this->branch_id = $this->user->branch_id;
            $this->is_active = (bool) $this->user->is_active;
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->user?->id)],
            'password' => $this->user ? 'nullable|min:8' : 'required|min:8',
            'role' => Rule::in(self::ROLES),
            'branch_id' => 'nullable|exists:branches,id',
            'is_active' => 'boolean',
        ];
    }

    public function save(): void
    {
        Gate::authorize('manage-users');

        if (config('lms.demo')) {
            $this->addError('name', __('Account changes are disabled in demo mode.'));

            return;
        }

        $data = $this->validate();

        if ($data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if ($this->user) {
            // The last active admin must not lock themselves out.
            if ($this->user->role === 'admin' && $data['role'] !== 'admin'
                && User::where('role', 'admin')->where('is_active', true)->count() === 1) {
                $this->addError('role', __('Cannot demote the last active admin.'));

                return;
            }

            // ...nor deactivate themselves out: EnsureActive logs them out
            // on the next request and there is no CLI recovery path.
            if ($this->user->role === 'admin' && $this->user->is_active && ! $data['is_active']
                && User::where('role', 'admin')->where('is_active', true)->count() === 1) {
                $this->addError('is_active', __('Cannot deactivate the last active admin.'));

                return;
            }

            $this->user->update($data);
        } else {
            User::create($data);
        }

        session()->flash('status', __('User saved'));
        $this->redirectRoute('users.index');
    }

    public function delete(): void
    {
        Gate::authorize('manage-users');

        if (config('lms.demo')) {
            $this->addError('name', __('Account changes are disabled in demo mode.'));

            return;
        }

        if (! $this->user) {
            return;
        }

        if ($this->user->id === auth()->id()) {
            $this->addError('name', __('You cannot delete your own account.'));

            return;
        }

        if ($this->user->role === 'admin'
            && User::where('role', 'admin')->where('is_active', true)->count() === 1) {
            $this->addError('name', __('Cannot delete the last active admin.'));

            return;
        }

        // Deleting nulls out audit FKs (received_by, approved_by, ...) and
        // erases who touched the money — such accounts get deactivated,
        // never deleted.
        $userId = $this->user->id;
        $hasFinancialHistory = LoanPayment::query()
            ->where(fn ($q) => $q->where('received_by', $userId)->orWhere('reversed_by', $userId))
            ->exists()
            || Loan::withTrashed()
                ->where(fn ($q) => $q
                    ->where('approved_by', $userId)
                    ->orWhere('disbursed_by', $userId)
                    ->orWhere('created_by', $userId))
                ->exists()
            || JournalEntry::where('created_by', $userId)->exists();

        if ($hasFinancialHistory) {
            $this->addError('name', __('This user is referenced by financial records and cannot be deleted — deactivate the account instead.'));

            return;
        }

        $this->user->delete();

        session()->flash('status', __('User deleted'));
        $this->redirectRoute('users.index');
    }

    public function render(): View
    {
        return view('livewire.users.form', [
            'roleOptions' => collect(self::ROLES)->mapWithKeys(
                fn ($role) => [$role => ucwords(str_replace('_', ' ', $role))]
            )->all(),
            'branchOptions' => Branch::orderBy('name')->get(['id as value', 'name as label']),
        ]);
    }
}
