<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\{Computed, Title, Validate};

use App\Models\Search;
use App\Models\AudioFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new #[Title('New Search')] class extends Component
{
    use WithFileUploads;
    // The list of files without data
    public $fileList = [];

    #[Validate('required')]
    public $query = '';
    #[Validate(
        [
            'uploadQueue' => 'required',
            'uploadQueue.*' => [
                'required',
                'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4,application/octet-stream',
            ],
        ],
        attribute: [
            'uploadQueue.*' => 'file',
        ],
        message: [
            'uploadQueue' => 'Please select at least one audio file.',
            'uploadQueue.*' => 'Please select an audio file.',
        ],
    )]
    public $uploadQueue = [];

    // Check if any item in $fileList has not been uploaded
    #[Computed]
    public function isUploading(): bool
    {
        return collect($this->fileList)->some(function ($file) {
            return !$file['uploaded'];
        });
    }

    public function addFiles($fileInfo)
    {
        $this->fileList = $fileInfo;
        // Reset upload queue
        $this->uploadQueue = [];
    }

    public function fileFinished($index)
    {
        $this->fileList[$index]['uploaded'] = true;
    }

    public function removeFile($index)
    {
        unset($this->fileList[$index]);
        unset($this->uploadQueue[$index]);
    }

    public function submitFiles()
    {
        $this->validate();

        $searchModel = DB::transaction(function () {
            // Create Search DB entry
            $searchEntry = Search::create([
                'user_id' => Auth::id(),
                'query' => $this->query,
            ]);

            $fileEntries = [];
            foreach ($this->uploadQueue as $file) {
                // Store file
                $path = $file->store(path: 'audioFiles');

                // Create new AudioFile DB entry
                $fileEntries[] = new AudioFile([
                    'audio_path' => $path,
                    'audio_filename' => $file->getClientOriginalName(),
                ]);

                // Add file entries to related search and save
                $searchEntry->files()->saveMany($fileEntries);
                // Load the newly added files
                $searchEntry->load('files');
            }

            return $searchEntry;
        });

        $this->redirectRoute('search', ['id' => $searchModel->id], navigate: true);
    }
}; ?>

<div>
    <div>
        <flux:heading size="xl" level="1">Find a word or phrase</flux:heading>
        <flux:subheading>Upload a new file and enter a word or phrase to find.</flux:subheading>

        <flux:separator class="my-4" />

        <form wire:submit.prevent="submitFiles" class="*:mb-4">
            <flux:input type="text" wire:model="query" label="Word or Phrase" />

            <flux:fieldset>
                <flux:legend>Audio Files</flux:legend>

                @if (!$fileList)
                <flux:input type="file" label="Select one or more audio files" multiple
                    x-on:change="$wire.$js.handleFileSelection" accept="audio/*" />
                @else
                <div class="flex flex-row flex-wrap gap-2 items-center justify-between">
                    <div class="flex flex-row gap-2 items-center">
                        <flux:heading>Selected {{ count($fileList) }} File/s</flux:heading>
                        @if ($this->isUploading)
                        <flux:icon.loading variant="micro" />
                        @else
                        <flux:icon.check variant="mini" class="text-accent" />
                        @endif
                    </div>

                    <flux:button size="sm" wire:click="addFiles([])" label="Remove all files from upload queue" variant="subtle">
                        Clear All
                    </flux:button>
                </div>
                @endif

                @error('uploadQueue')
                <flux:callout variant="danger" icon="exclamation-circle" heading="{{ $message }}" />
                @enderror

                <ul class="mt-2 flex flex-col gap-2">
                    @foreach ($fileList as $file_id => $file)
                    <li wire:key="{{ $file_id }}">
                        <flux:callout variant="{{ $errors->has('uploadQueue.' . $file_id) ? 'danger' : 'default' }}" inline>
                            <flux:callout.heading>{{ $file['name'] }}</flux:callout.heading>
                            @error('uploadQueue.' . $file_id) <flux:callout.text>{{ $message }}</flux:callout.text> @enderror

                            <x-slot name="controls" class="flex flex-row items-center gap-2">
                                @if (!$file['uploaded'])
                                <flux:icon.loading variant="micro" />
                                @else
                                <flux:icon.check variant="mini" class="[--callout-icon:var(--color-accent)]" />
                                @endif

                                <flux:button wire:click="removeFile({{ $file_id }})"
                                    :disabled="!$file['uploaded']"
                                    icon="x-mark" size="sm" label="Remove file from upload queue"
                                    variant="subtle">
                                </flux:button>
                            </x-slot>
                        </flux:callout>
                    </li>
                    @endforeach
                </ul>
            </flux:fieldset>

            <flux:button type="submit" variant="primary" :disabled="$this->isUploading">Search</flux:button>
        </form>
    </div>
</div>

@script
<script>
    /**
     * Uploads selected files one at a time through a livewire method.
     * @param {Event} event
     */
    $js('handleFileSelection', (event) => {
        /**
         * Creates array of file info objects from FileList object
         * @param {FileList} files
         * @returns {Array}
         */
        function createFileInfo(files) {
            let fileInfo = [];
            for (const file of files) {
                fileInfo.push({
                    name: file.name,
                    type: file.type,
                    size: file.size,
                    uploaded: false,
                });
            }
            return fileInfo;
        }

        // Reset server-side file lists
        $wire.addFiles([]);
        // Get the file list object from the event target
        const fileList = event.target.files;

        $wire.addFiles(createFileInfo(fileList));

        for (let i = 0; i < fileList.length; i++) {
            $wire.$upload('uploadQueue', fileList[i], finish = () => $wire.fileFinished(i));
        }
    });
</script>
@endscript
