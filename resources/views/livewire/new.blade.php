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
use Illuminate\Support\Facades\Storage;

new #[Title('New Search')] class extends Component
{
    use WithFileUploads;

    // Track individual files with their metadata
    public $fileList = [];
    public $canSubmit = true;

    #[Validate('required')]
    public $query = '';

    #[Validate('required')]
    public $completionEmail = true;

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
        ]
    )]
    public $uploadQueue = [];

    public function removeFile($index)
    {
        $this->canSubmit = false;
        unset($this->fileList[$index]);
        unset($this->uploadQueue[$index]);
        // Reindex arrays to avoid gaps
        $this->fileList = array_values($this->fileList);
        $this->uploadQueue = array_values($this->uploadQueue);
        $this->canSubmit = true;
    }

    public function clearFiles()
    {
        $this->canSubmit = false;
        $this->fileList = [];
        $this->uploadQueue = [];
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
        // Validate the form
        $this->validate();

        if ($this->canSubmit && count($this->uploadQueue) > 0) {
            $searchModel = DB::transaction(function () {
                // Create Search DB entry
                $searchEntry = Search::create([
                    'user_id' => Auth::id(),
                    'query' => $this->query,
                    'status' => SearchStatus::Processing,
                    'completion_email' => $this->completionEmail,
                ]);

                $fileEntries = [];
                foreach ($this->uploadQueue as $index => $file) {
                    // Get the original filename from our fileList
                    $originalFilename = $this->fileList[$index]['name'];

                    // Store file
                    $path = Storage::putFile('audioFiles', $file);

                    // Attempt parsing of date from client file name
                    $parsedDate = $this->parseDate($originalFilename);

                    // Sanitize client file name
                    $clientName = preg_replace('/[^\w\-\.\s]/', '', $originalFilename);
                    $clientName = substr($clientName, 0, 255);

                    // Create new AudioFile DB entry
                    $fileEntry = new AudioFile([
                        'audio_path' => $path,
                        'audio_filename' => $clientName,
                        'parsed_date' => $parsedDate,
                    ]);


                    // Add entry to array
                    $fileEntries[] = $fileEntry;
                }

                // Add file entries to related search and save
                $searchEntry->files()->saveMany($fileEntries);

                // Dispatch ProcessFile Jobs
                foreach ($fileEntries as $fileEntry) {
                    ProcessFile::dispatch($searchEntry, $fileEntry);
                }

                return $searchEntry;
            });

            Log::info('Created New Search: search "{query}"');

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

                addFiles(event) {
                    // Get files from input
                    const files = event.target.files;

                    if (files.length === 0) return;

                    // Start upload process
                    this.canSubmit = false;

                    // Define allowed audio MIME types
                    const allowedTypes = ['audio/wav','audio/x-wav','audio/mpeg','audio/mp3','audio/mp4','audio/aac','audio/ogg','audio/webm','audio/flac','application/octet-stream'];


                    // Maximum file size in bytes (25MB)
                    const maxFileSize = 25 * 1024 * 1024;

                    // Process each file individually
                    for (let i = 0; i < files.length; i++) {
                        const file = files[i];

                        // Add to local preview immediately
                        const newIndex = this.localFiles.length;
                        // Validate file type and size
                        const isAudioFile = allowedTypes.includes(file.type) ||
                           file.name.match(/\.(mp3|wav|ogg|aac|m4a|flac)$/i) !== null;
                        const isValidSize = file.size <= maxFileSize;

                        this.localFiles.push({
                            name: file.name,
                            type: file.type,
                            size: file.size,
                            uploaded: false,
                            error: !isAudioFile || !isValidSize,
                            errorMessage: !isAudioFile
                                ? 'Unsupported file type.'
                                : (!isValidSize ? 'File exceeds maximum size of 25MB' : '')
                        });

                        // Upload the file
                        if (isAudioFile && isValidSize) {
                            $wire.upload('uploadQueue.' + newIndex, file,
                                (uploadedFilename) => {
                                    // Success callback
                                    this.localFiles[newIndex].uploaded = true;
                                },
                                (error) => {
                                    // Error callback
                                    this.localFiles[newIndex].error = true;
                                    this.localFiles[newIndex].errorMessage = error;
                                },
                                (progress) => {
                                    // Progress callback if needed
                                }
                            );
                        }
                    }

                    this.canSubmit = true;

                    // Clear the input so the same files can be selected again if needed
                    event.target.value = '';
                }
            }">
            <flux:input type="text" wire:model="query" label="Word or Phrase" />

            <flux:switch wire:model.live="completionEmail" label="Completion email" description="Receive an email when the search is complete." />

            <flux:fieldset>
                <flux:legend>Audio Files</flux:legend>

                <!-- File input is always visible -->
                <div>
                    <flux:input type="file" label="Select one or more audio files. Invalid files (marked with red triangle) will NOT be uploaded." multiple
                        x-on:change="addFiles" accept="audio/*" />
                </div>

                @error('uploadQueue')
                <flux:callout class="my-2" variant="danger" icon="exclamation-triangle" heading="{{ $message }}" />
                @enderror
                @error('uploadQueue.*')
                <flux:callout class="my-2" variant="danger" icon="exclamation-triangle" heading="{{ $message }}" />
                @enderror

                <!-- Show file list if files are selected -->
                <div wire:cloak x-show="localFiles.length > 0" class="flex flex-row flex-wrap gap-2 items-center justify-between mt-4">
                    <div class="flex flex-row gap-2 items-center">
                        <flux:heading>Selected <span x-text="localFiles.length"></span> File/s</flux:heading>
                        <template x-if="!canSubmit">
                            <flux:icon.loading variant="micro" />
                        </template>
                        <template x-if="canSubmit">
                            <flux:icon.check variant="mini" class="text-accent" />
                        </template>
                    </div>

                    <flux:button size="sm" wire:click="clearFiles" label="Remove all files from upload queue" variant="subtle">
                        Clear All
                    </flux:button>
                </div>

                <!-- Display file list -->
                <ul class="mt-2 flex flex-col gap-2">
                    <template x-for="(file, index) in localFiles" :key="index">
                        <li>
                            <flux:callout inline>
                                <flux:callout.heading x-text="file.name"></flux:callout.heading>

                                <template x-if="file.error">
                                    <flux:callout.text><span x-text="file.errorMessage"></span> This file will NOT be uploaded.</flux:callout.text>
                                </template>

                                <x-slot name="controls" class="flex flex-row items-center gap-2">
                                    <template x-if="!file.uploaded && !file.error">
                                        <flux:icon.loading variant="micro" />
                                    </template>
                                    <template x-if="file.uploaded">
                                        <flux:icon.check variant="mini" class="[--callout-icon:var(--color-accent)]" />
                                    </template>
                                    <template x-if="file.error">
                                        <flux:icon.exclamation-triangle variant="mini" class="[--callout-icon:var(--color-red-400)]" />
                                    </template>
                                    <flux:button @click="$wire.removeFile(index)"
                                        x-bind:disabled="!file.uploaded && !file.error"
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
