<?php

namespace App\Livewire\Borrowers;

use App\Models\Borrower;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?Borrower $borrower = null;

    public $photo = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $phone = '';

    public string $email = '';

    public string $id_number = '';

    public string $address = '';

    public string $notes = '';

    public function mount(?Borrower $borrower = null): void
    {
        if ($borrower !== null && $borrower->exists) {
            $this->borrower = $borrower;

            // Branch-scoped staff must not read or rewrite another
            // branch's borrower by URL (branchless records stay open).
            $this->authorizeBranch($this->borrower);

            foreach (['first_name', 'last_name', 'phone', 'email', 'id_number', 'address', 'notes'] as $field) {
                $this->{$field} = (string) ($this->borrower->{$field} ?? '');
            }
        }
    }

    private function authorizeBranch(Borrower $borrower): void
    {
        $scoped = auth()->user()?->scopedBranchId();
        abort_if($scoped !== null && $borrower->branch_id !== null && (int) $borrower->branch_id !== $scoped, 403);
    }

    protected function rules(): array
    {
        return [
            'first_name' => 'required|min:2|max:255',
            'last_name' => 'nullable|max:255',
            'phone' => 'nullable|max:32',
            'email' => 'nullable|email|max:255',
            'id_number' => 'nullable|max:64',
            'address' => 'nullable|max:2000',
            'notes' => 'nullable|max:5000',
            'photo' => 'nullable|image|max:2048',
        ];
    }

    public function save(): void
    {
        Gate::authorize('create-loans');

        $data = $this->validate();
        unset($data['photo']);

        if ($this->photo) {
            // Private disk: PII photos are served through the authenticated
            // /media route, never as world-readable /storage URLs.
            $data['photo_path'] = $this->photo->store('borrowers', 'local');
        }

        if ($this->borrower) {
            // Re-check on save: mount's check alone would not survive a
            // forged Livewire snapshot.
            $this->authorizeBranch($this->borrower);

            $this->borrower->update($data);
        } else {
            $data['created_by'] = auth()->id();
            $data['branch_id'] = auth()->user()?->branch_id;
            Borrower::create($data);
        }

        session()->flash('status', __('Borrower saved'));
        $this->redirectRoute('borrowers.index');
    }

    public function render(): View
    {
        return view('livewire.borrowers.form')->title($this->borrower?->exists ? __('Edit borrower') : __('New borrower'));
    }
}
