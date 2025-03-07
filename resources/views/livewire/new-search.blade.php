<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Validate;

new class extends Component
{
    use WithFileUploads;

    #[Validate(['files.*' => 'mimetypes:audio/x-wa,audio/mpeg'])]
    public $files = [];
    public $query = '';
    public $submitted = false;

    private function save()
    {
        foreach ($this->files as $file) {
            $file->store(path: 'audioFiles');
        }
    }

    public function search()
    {
        $this->submitted = true;
        $this->save();
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">Find a word or phrase</flux:heading>
    <flux:subheading>Upload a new file and enter a word or phrase to find.</flux:subheading>

    <flux:separator class="my-4" />

    <form wire:submit="search" class="*:mb-4">
        <flux:input type="file" wire:model="files" label="Audio Files" multiple />

        @error('files.*') <flux:heading class="error">{{ $message }}</flux:heading> @enderror

        <flux:input type="text" wire:model="query" label="Word or Phrase" />
        <flux:button type="submit" variant="primary">Search</flux:button>
    </form>

    <flux:heading wire:show="submitted">Searching {{ count($files) }} files for "{{ $query }}"...</flux:heading>
</div>
