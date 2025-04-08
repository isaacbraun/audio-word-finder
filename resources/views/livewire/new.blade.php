<?php

use App\Models\Search;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\{Title, Validate};
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

new #[Title('New Search')] class extends Component
{
    use WithFileUploads;

    #[Validate('required')]
    public $query = '';

    #[Validate('required')]
    public $completionEmail = true;

    #[Validate(
        [
            'uploadedFiles' => 'required',
            'uploadedFiles.*' => [
                'required',
                'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4',
                'max:25600',
            ],
        ],
        attribute: [
            'uploadedFiles.*' => 'file',
        ],
        message: [
            'uploadedFiles' => 'Please select at least one audio file.',
            'uploadedFiles.*' => 'Please select audio files.',
        ]
    )]
    public $uploadedFiles = [];

    public $canSubmit = true;

    public function removeFile($index)
    {
        $this->canSubmit = false;
        // Remove and Reindex array to avoid gaps
        unset($this->uploadedFiles[$index]);
        $this->uploadedFiles = array_values($this->uploadedFiles);
        $this->canSubmit = true;
    }

    public function clearFiles()
    {
        $this->canSubmit = false;
        $this->uploadedFiles = [];
        $this->canSubmit = true;
    }

    public function submitFiles()
    {
        // Validate the form
        $this->validate();

        if ($this->canSubmit && count($this->uploadedFiles) > 0) {
            // Create search with files
            $search = Search::createWithFiles(
                searchData: [
                    'user_id' => Auth::id(),
                    'query' => $this->query,
                    'completion_email' => $this->completionEmail,
                ],
                files: $this->uploadedFiles,
            );

            Log::info('Created New Search: search "{query}"', ['query' => $this->query]);

            $this->redirectRoute('search', ['id' => $search->id], navigate: true);
        }
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">Find a word or phrase</flux:heading>
    <flux:subheading>Upload a new file and enter a word or phrase to find.</flux:subheading>

    <flux:separator class="my-4" />

    <form wire:submit.prevent="submitFiles" class="*:mb-4">
        <flux:input type="text" wire:model="query" label="Word or Phrase" />

        <flux:switch wire:model.live="completionEmail" label="Completion email" description="Receive an email when the search is complete." />

        <div x-data="uploadHandler">
            <flux:label>Audio Files</flux:label>

            <flux:field class="mt-2">
                <flux:error name="uploadedFiles" />

                <flux:input type="file" multiple accept="audio/*" id="fileInput" x-on:change="onFileInputChanged" />

                <flux:label
                    for="fileInput"
                    x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'"
                    x-on:drop.prevent="onFileDropped">
                    <flux:card class="text-center border-dashed border-2 px-16">
                        <flux:text>Choose or drop files</flux:text>
                    </flux:card>
                </flux:label>

                <flux:description>Invalid files (marked with red triangle) will NOT be uploaded.</flux:description>
            </flux:field>

            @error('uploadedFiles')
            <flux:callout class="my-2" variant="danger" icon="exclamation-triangle" heading="{{ $message }}" />
            @enderror
            @error('uploadedFiles.*')
            <flux:callout class="my-2" variant="danger" icon="exclamation-triangle" heading="{{ $message }}" />
            @enderror

            <div x-cloak x-show="localFiles.length > 0" class="flex flex-row flex-wrap gap-2 items-center justify-between mt-4">
                <div class="flex flex-row gap-2 items-center">
                    <flux:heading>Selected <span x-text="localFiles.length"></span> File/s</flux:heading>
                    <template x-if="!uploading">
                        <flux:icon.check variant="mini" class="text-accent" />
                    </template>
                    <template x-if="uploading">
                        <flux:icon.loading variant="micro" />
                    </template>
                </div>

                <flux:button size="sm" @click="clear" label="Remove all files from upload queue" variant="subtle">
                    Clear All
                </flux:button>
            </div>

            <ul class="mt-2 flex flex-col gap-2" x-show="localFiles.length" x-cloak>
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
                                <flux:button @click="remove(index)"
                                    x-bind:disabled="!file.uploaded && !file.error"
                                    icon="x-mark" size="sm" label="Remove file from upload queue"
                                    variant="subtle">
                                </flux:button>
                            </x-slot>

                        </flux:callout>
                    </li>
                </template>
            </ul>
        </div>

        <flux:button type="submit" variant="primary">Search</flux:button>
    </form>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('uploadHandler', () => ({
            localFiles: [],
            uploading: false,

            onFileDropped(event) {
                this._addFiles(event.dataTransfer.files);
            },

            onFileInputChanged(event) {
                this._addFiles(event.target.files);
            },

            remove(index) {
                this.localFiles.splice(index, 1);
                this.$wire.removeFile(index);
            },

            clear() {
                this.localFiles = [];
                this.$wire.clearFiles();
            },

            _addFiles(files) {
                if (files.length === 0) return;

                this.uploading = true;

                // Reset files to avoid conflicts
                this.clear();

                const fileArray = Array.from(files);

                Promise.allSettled(fileArray.map((file, index) => {
                    // Add to local preview immediately
                    const error = this._initFileItem(file);

                    if (error) {
                        return Promise.reject("Invalid file type or size");
                    }

                    // Upload the file
                    return this._uploadFile(index, file);
                })).then(() => {
                    this.uploading = false;
                }).catch(() => {
                    this.uploading = false;
                });
            },

            /**
             * Creates and adds a new file item to localFiles array.
             * @param {File} file - The File object to add.
             * @returns {void}
             */
            _initFileItem(file) {
                const allowedTypes = ['audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/webm', 'audio/flac'];
                const maxFileSize = 25 * 1024 * 1024; // 25MB

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
                    errorMessage: !isAudioFile ?
                        'Unsupported file type.' : (!isValidSize ? 'File exceeds maximum size of 25MB' : '')
                });

                return !isAudioFile || !isValidSize;
            },

            /**
             * Upload file and handle callbacks.
             * @param {File} file - The File object to upload.
             */
            _uploadFile(index, file) {
                return new Promise((resolve, reject) => {
                    this.$wire.upload('uploadedFiles.' + index, file,
                        (uploadedFilename) => {
                            this.localFiles[index].uploaded = true;
                            resolve("Upload successful");
                        },
                        (error) => {
                            this.localFiles[index].error = true;
                            this.localFiles[index].errorMessage = error;
                            reject("Upload failed");
                        },
                    );
                });
            }
        }));
    });
</script>
