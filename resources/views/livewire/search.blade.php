<?php

use Livewire\Volt\Component;
use App\Models\Search;
use Livewire\Attributes\Title;

new #[Title('Search Results')] class extends Component {
    public $id;
    public $search;
    public $query;
    public $files;

    public function mount($id)
    {
        $this->id = $id;
        $this->search = Search::findOrFail($id);

        $this->authorize('view', $this->search);

        $this->query = $this->search->query;
        $this->files = $this->search->files;
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">Results for "{{ $query }}"</flux:heading>
    <flux:subheading>Searching {{ count($files) }} files.</flux:subheading>

    <div class="mt-4 flex flex-col gap-4">
        @foreach ($files as $file)
        <livewire:result-card :file="$file" wire:key="{{ $file->id }}" />
        @endforeach
    </div>
</div>
