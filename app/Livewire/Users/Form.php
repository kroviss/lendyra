<?php

namespace App\Livewire\Users;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\View\View;
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

    public function mount(?int $user = null): void
    {
        if ($user !== null) {
            $this->user = User::findOrFail($user);

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

            $this->user->update($data);
        } else {
            User::create($data);
        }

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
