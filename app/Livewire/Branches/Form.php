<?php

namespace App\Livewire\Branches;

use App\Models\Branch;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?Branch $branch = null;

    public string $name = '';
    public string $code = '';
    public string $phone = '';
    public string $address = '';
    public bool $is_active = true;

    public function mount(?int $branch = null): void
    {
        if ($branch !== null) {
            $this->branch = Branch::findOrFail($branch);

            $this->name = $this->branch->name;
            $this->code = $this->branch->code;
            $this->phone = (string) $this->branch->phone;
            $this->address = (string) $this->branch->address;
            $this->is_active = (bool) $this->branch->is_active;
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|min:2|max:255',
            'code' => ['required', 'max:16', 'alpha_dash', Rule::unique('branches', 'code')->ignore($this->branch?->id)],
            'phone' => 'nullable|max:32',
            'address' => 'nullable|max:2000',
            'is_active' => 'boolean',
        ];
    }

    public function save(): void
    {
        \Illuminate\Support\Facades\Gate::authorize('manage-products');

        $data = $this->validate();

        if ($this->branch) {
            $this->branch->update($data);
        } else {
            Branch::create($data);
        }

        session()->flash('status', __('Branch saved'));
        $this->redirectRoute('branches.index');
    }

    public function delete(): void
    {
        \Illuminate\Support\Facades\Gate::authorize('manage-products');

        if (! $this->branch) {
            return;
        }

        if ($this->branch->users()->exists() || $this->branch->loans()->withTrashed()->exists()) {
            $this->addError('name', __('Cannot delete: users or loans are attached to this branch.'));

            return;
        }

        $this->branch->delete();

        session()->flash('status', __('Branch deleted'));
        $this->redirectRoute('branches.index');
    }

    public function render(): View
    {
        return view('livewire.branches.form');
    }
}
