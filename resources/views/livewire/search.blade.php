<?php

use Livewire\Volt\Component;
use App\Models\Search;
use Livewire\Attributes\{Title};

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

    <flux:separator class="my-4" />

    <flux:heading size="lg" level="2">Summary</flux:heading>
    <div wire:poll.5s>
        @if ($search->query_total)
        <flux:heading>{{ $search->query_total }} Matches Found</flux:heading>
        @else
        <flux:icon.loading />
        @endif
    </div>

    <flux:separator class="my-4" />

    <flux:heading size="lg" level="2">Files</flux:heading>
    <div class="mt-4 flex flex-col gap-4">
        @foreach ($files as $file)
        <livewire:result-card :file="$file" wire:key="{{ $file->id }}" />
        @endforeach
    </div>
</div>
