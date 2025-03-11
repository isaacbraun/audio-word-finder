<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\{Title, Validate};

use App\Models\Search;
use App\Models\UploadedFile;
use Illuminate\Support\Facades\DB;

new #[Title('New Search')] class extends Component
{
    use WithFileUploads;

    // TODO: fix validation error message.
    // I think validation is working correctly, but the error message is not
    // displaying correctly.
    #[
        Validate(
            ['uploadedFiles.*' => 'required|mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4'],
            message: 'Please select an audio file.',
        )
    ]
    public $uploadedFiles = [];

    #[Validate('required')]
    public $query = '';

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

            // Load the newly added files
            $searchEntry->load('files');

            return $searchEntry;
        });
    }

    public function search()
    {
        // Save submission to storage/DB
        $searchModel = $this->save();
        // Redirect to search results
        $this->redirectRoute('search', ['id' => $searchModel->id]);
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
</div>
