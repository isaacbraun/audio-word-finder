<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Models\Search;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadFile implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Search $search,
        protected array $file,
        protected string $timezone,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()->cancelled()) {
            // Determine if the batch has been cancelled...
            return;
        }

        $tempPath = "livewire-tmp/{$this->file['path']}";
        $newPath = "audioFiles/{$this->file['path']}";

        try {
            Storage::move($tempPath, $newPath);
        } catch (\Exception $e) {
            Log::error('UploadFiles: error moving file', ['exception' => $e]);
        }

        // Create the audio file record
        $file = AudioFile::createFromUpload(
            searchId: $this->search->id,
            path: $newPath,
            originalFilename: $this->file['name'],
            timezone: $this->timezone,
        );

        // Queue the file for processing
        $this->batch()->add(new ProcessFile($this->search, $file));
    }
}
