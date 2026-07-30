<?php

namespace App\Livewire\Borrowers;

use App\Models\Borrower;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Form extends Component
{
    public ?Borrower $borrower = null;

    public string $first_name = '';
    public string $last_name = '';
    public string $phone = '';
    public string $email = '';
    public string $id_number = '';
    public string $address = '';
    public string $notes = '';

    public function mount(?int $borrower = null): void
    {
        if ($borrower !== null) {
            $this->borrower = Borrower::findOrFail($borrower);

            foreach (['first_name', 'last_name', 'phone', 'email', 'id_number', 'address', 'notes'] as $field) {
                $this->{$field} = (string) ($this->borrower->{$field} ?? '');
            }
        }
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
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->borrower) {
            $this->borrower->update($data);
        } else {
            $data['created_by'] = auth()->id();
            Borrower::create($data);
        }

        $this->redirectRoute('borrowers.index');
    }

    public function render(): View
    {
        return view('livewire.borrowers.form');
    }
}
