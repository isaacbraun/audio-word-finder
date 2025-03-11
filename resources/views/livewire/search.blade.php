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
        $this->query = $this->search->query;
        $this->files = $this->search->files;
    }
}; ?>

<div>
    <div class="mt-4">
        <flux:separator />

        <flux:heading size="xl" level="2">Results for "{{ $query }}"</flux:heading>
        <flux:subheading>Searching {{ count($files) }} files.</flux:subheading>

        <flux:accordion>
            @foreach ($files as $file)
            <flux:accordion.item>
                <flux:accordion.heading>{{ $file->audio_filename }}</flux:accordion.heading>

                <flux:accordion.content>{{ $file->audio_path }}</flux:accordion.content>
            </flux:accordion.item>
            @endforeach
        </flux:accordion>
    </div>
</div>
