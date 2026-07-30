<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class Profile extends Component
{
    public string $name = '';
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->name = auth()->user()->name;
    }

    public function save(): void
    {
        if (config('lms.demo')) {
            $this->addError('name', __('Account changes are disabled in demo mode.'));

            return;
        }

        $this->validate([
            'name' => 'required|min:2|max:255',
            'current_password' => 'required|current_password',
            'password' => 'nullable|min:8|confirmed',
        ]);

        $user = auth()->user();
        $user->name = $this->name;

        if ($this->password !== '') {
            $user->password = Hash::make($this->password);
            // Invalidate stolen remember-me cookies on password change.
            $user->setRememberToken(\Illuminate\Support\Str::random(60));
        }

        $user->save();

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->dispatch('toast', message: __('Profile updated'));
    }

    public function render(): View
    {
        return view('livewire.profile');
    }
}
