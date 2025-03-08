<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Computed;

use App\Models\Search;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\DB;

new class extends Component
{
    use WithFileUploads;

    // TODO: fix validation
    #[Validate([
        'uploadedFiles' => [
            'mimes:m4a,wav,mp3',
        ],
        // 'uploadedFiles.*' => [
        //     'mimetypes:audio/mpeg,audio/mp4',
        // ],
    ])]
    public $uploadedFiles = [];
    #[Validate('required')]
    public $query = '';
    public $showResults = false;
    public $searchModel = null;

    #[Computed]
    public function files()
    {
        if ($this->searchModel) {
            return $this->searchModel->files()->get();
        } else {
            return collect([]);
        }
    }

    private function save(): Search
    {
        $this->validate();

        return DB::transaction(function () {
            // Create search entry
            $searchEntry = Search::create([
                'query' => $this->query,
            ]);

            $fileEntries = [];
            foreach ($this->uploadedFiles as $file) {
                // Store file
                $path = $file->store(path: 'audioFiles');

                // Create new file entry
                $fileEntries[] = new UploadedFile([
                    'audio_path' => $path,
                    'audio_filename' => $file->getClientOriginalName(),
                ]);
            }

            // Add file entries to related search and save
            $searchEntry->files()->saveMany($fileEntries);

            return $searchEntry;
        });
    }

    public function search()
    {
        // Save submission to storage/DB
        $this->searchModel = $this->save();
        $this->showResults = true;
        // Start job
    }
}; ?>

<div>
    <div>
        <flux:heading size="xl" level="1">Find a word or phrase</flux:heading>
        <flux:subheading>Upload a new file and enter a word or phrase to find.</flux:subheading>

        <flux:separator class="my-4" />

        <form wire:submit="search" class="*:mb-4">
            <flux:input type="file" wire:model="uploadedFiles" label="Audio Files" multiple />
            <flux:input type="text" wire:model="query" label="Word or Phrase" />
            <flux:button type="submit" variant="primary">Search</flux:button>
        </form>
    </div>

    @if (count($this->files()) > 0)
    <div class="mt-4">
        <flux:separator />

        <flux:heading size="lg" level="2">Results for "{{ $query }}"</flux:heading>
        <flux:subheading>Searching {{ count($files) }} files.</flux:subheading>

        <flux:accordion>
            @foreach ($this->files() as $file)
            <flux:accordion.item>
                <flux:accordion.heading>{{ $file->audio_filename }}</flux:accordion.heading>

                <flux:accordion.content>{{ $file->audio_path }}</flux:accordion.content>
            </flux:accordion.item>
            @endforeach
        </flux:accordion>
    </div>
    @endif
</div>
