<?php

use App\Models\Search;
use App\Models\AudioFile;
use App\Jobs\ProcessFile;
use App\Enums\SearchStatus;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\{Title, Validate};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

new #[Title('New Search')] class extends Component
{
    use WithFileUploads;
    // The list of files without data
    public $fileList = [];
    public $canSubmit = true;

    #[Validate('required')]
    public $query = '';
    #[Validate(
        [
            'uploadQueue' => 'required',
            'uploadQueue.*' => [
                'required',
                'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4,application/octet-stream',
                'max:25600',
            ],
        ],
        attribute: [
            'uploadQueue.*' => 'file',
        ],
        message: [
            'uploadQueue' => 'Please select at least one audio file.',
            'uploadQueue.*' => 'Please select audio files.',
        ],
    )]
    public $uploadQueue = [];
    #[Validate('required')]
    public $completionEmail = true;

    public function removeFile($index)
    {
        $this->canSubmit = false;
        unset($this->fileList[$index]);
        unset($this->uploadQueue[$index]);
        $this->canSubmit = true;
    }

    private function parseDate(string $filename): Carbon | null
    {
        // Strict regex that only matches the WSMC skimmer format
        $pattern = "/(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})/";

        if (!preg_match($pattern, $filename, $matches)) {
            // Return null
            return null;
        }

        try {
            $dateString = $matches[1]; // 2025-02-17
            $timeString = str_replace('-', ':', $matches[2]); // Convert 14-25-13 to 14:25:13

            // Create Carbon instance with validation
            $dateTime = Carbon::createFromFormat('Y-m-d H:i:s', "$dateString $timeString");

            // Additional validation - ensure the date is reasonable
            $now = Carbon::now();
            $minDate = $now->copy()->subYears(5);
            $maxDate = $now->copy()->addYears(1);

            if ($dateTime->lt($minDate) || $dateTime->gt($maxDate)) {
                // Date is suspiciously old or in the future
                throw new \Exception('Filename date out of reasonable range: ' . $dateTime->toDateTimeString());
            }

            return $dateTime;
        } catch (\Exception $e) {
            // Handle date parsing errors
            Log::info('New Search: filename date parsing issue', ['exception' => $e->getMessage()]);
            return null;
        }
    }

    public function submitFiles()
    {
        $this->validate();

        if ($this->canSubmit) {
            $searchModel = DB::transaction(function () {
                // Create Search DB entry
                $searchEntry = Search::create([
                    'user_id' => Auth::id(),
                    'query' => $this->query,
                    'status' => SearchStatus::Processing,
                    'completion_email' => $this->completionEmail,
                ]);

                $fileEntries = [];
                foreach ($this->uploadQueue as $file) {
                    // Store file
                    $path = $file->store(path: 'audioFiles');

                    // Get client file name
                    $clientName = basename($file->getClientOriginalName());

                    // Attempt parsing of date from client file name
                    $parsedDate = $this->parseDate($clientName);

                    // Sanatize client file name
                    $clientName = preg_replace('/[^\w\-\.\s]/', '', $clientName);
                    $clientName = substr($clientName, 0, 255);

                    // Create new AudioFile DB entry - $parseDate may be intentionally null.
                    $fileEntry = new AudioFile([
                        'audio_path' => $path,
                        'audio_filename' => $clientName,
                        'parsed_date' => $parsedDate,
                    ]);

                    Log::info('New View: search "{query}" - adding file {name}', ['query' => $this->query, 'name' => $fileEntry->audio_filename]);

                    // Add entry to array
                    $fileEntries[] = $fileEntry;

                    // Add file entries to related search and save
                    $searchEntry->files()->saveMany($fileEntries);


                    // Dispatch ProcessFile Job
                    ProcessFile::dispatch($searchEntry, $fileEntry);
                }

                return $searchEntry;
            });

            $this->redirectRoute('search', ['id' => $searchModel->id], navigate: true);
        }
    }
}; ?>

<div>
    <div>
        <flux:heading size="xl" level="1">Find a word or phrase</flux:heading>
        <flux:subheading>Upload a new file and enter a word or phrase to find.</flux:subheading>

        <flux:separator class="my-4" />

        <form wire:submit.prevent="submitFiles" class="*:mb-4"
            x-data="{
                localFiles: $wire.entangle('fileList'),
                canSubmit: $wire.entangle('canSubmit'),

                handleFileSelection(event) {
                    // Get files from input
                    const files = event.target.files;

                    // Create local previews immediately
                    this.localFiles = [];
                    for (const file of files) {
                        this.localFiles.push({
                            name: file.name,
                            type: file.type,
                            size: file.size,
                            uploaded: false,
                            error: false,
                        });
                    }

                    // Start upload process
                    this.canSubmit = false;

                    for (let i = 0; i < files.length; i++) {
                        $wire.$upload('uploadQueue', files[i],
                            finish = () => {
                                this.localFiles[i].uploaded = true;
                                if (i === files.length - 1) {
                                    this.canSubmit = true;
                                }
                            },
                            error = (error) => {
                                this.localFiles[i].error = true;
                            }
                        );
                    }
                }
            }">
            <flux:input type="text" wire:model="query" label="Word or Phrase" />


            <flux:switch wire:model.live="completionEmail" label="Completion email" description="Receive an email when the search is complete." />

            <flux:fieldset>
                <flux:legend>Audio Files</flux:legend>

                <!-- Show file input if no files are selected -->
                <div x-show="localFiles.length === 0">
                    <flux:input type="file" label="Select one or more audio files" multiple
                        x-on:change="handleFileSelection" accept="audio/*" />
                </div>

                @error('uploadQueue')
                <flux:callout class="my-2" variant="danger" icon="exclamation-triangle" heading="{{ $message }}" />
                @enderror
                @error('uploadQueue.*')
                <flux:callout class="my-2" variant="danger" icon="exclamation-triangle" heading="{{ $message }}" />
                @enderror

                <!-- Show file list if files are selected -->
                <div x-show="localFiles.length > 0" class="flex flex-row flex-wrap gap-2 items-center justify-between">
                    <div class="flex flex-row gap-2 items-center">
                        <flux:heading>Selected <span x-text="localFiles.length"></span> File/s</flux:heading>
                        <template x-if="!canSubmit">
                            <flux:icon.loading variant="micro" />
                        </template>
                        <template x-if="canSubmit">
                            <flux:icon.check variant="mini" class="text-accent" />
                        </template>
                    </div>

                    <flux:button size="sm" @click="localFiles = [];" label="Remove all files from upload queue" variant="subtle">
                        Clear All
                    </flux:button>
                </div>

                <!-- Display local previews immediately -->
                <ul class="mt-2 flex flex-col gap-2">
                    <template x-for="(file, index) in localFiles" :key="index">
                        <li>
                            <flux:callout variant="default" inline>
                                <flux:callout.heading x-text="file.name"></flux:callout.heading>

                                <x-slot name="controls" class="flex flex-row items-center gap-2">
                                    <template x-if="!file.uploaded">
                                        <flux:icon.loading variant="micro" />
                                    </template>
                                    <template x-if="file.uploaded">
                                        <flux:icon.check variant="mini" class="[--callout-icon:var(--color-accent)]" />
                                    </template>
                                    <flux:button @click="$wire.removeFile(index)"
                                        x-bind:disabled="!file.uploaded"
                                        icon="x-mark" size="sm" label="Remove file from upload queue"
                                        variant="subtle">
                                    </flux:button>
                                </x-slot>
                            </flux:callout>
                        </li>
                    </template>

                </ul>
            </flux:fieldset>

            <flux:button type="submit" variant="primary" x-bind:disabled="!canSubmit">Search</flux:button>
        </form>
    </div>
</div>
