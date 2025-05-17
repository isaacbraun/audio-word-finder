<?php

namespace App\Jobs;

use App\Enums\FileStatus;
use App\Enums\SearchStatus;
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

    public $mimeTypes = ['audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/mp4'];

    public $maxFileSize = 25 * 1024 * 1024; // 25MB

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Search $search,
        protected array $file,
        protected string $timezone,
        protected int $fileCount,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Determine if the batch has been cancelled...
        if ($this->batch()->cancelled()) {
            Log::info('UploadFile: batch cancelled');

            return;
        }

        $tempPath = "livewire-tmp/{$this->file['path']}";
        $newPath = "audioFiles/{$this->file['path']}";

        // Check if file doesn't exist
        if (!Storage::exists($tempPath)) {
            // TODO: how should this be handled? Show error for file?
            Log::error('UploadFiles: file not found', ['path' => $tempPath]);
            return;
        }

        if ($this->validateFile($tempPath)) {
            $this->moveFile($tempPath, $newPath);
            $this->createAndQueue($newPath);
            $this->search->setStatusIfTrue(SearchStatus::Processing, FileStatus::Uploaded);
        }
    }

    public function validateFile($path): bool
    {
        $type = Storage::mimeType($path);
        $size = Storage::size($path);

        $validType = in_array($type, $this->mimeTypes);
        $validSize = $size <= $this->maxFileSize;

        if (! $validType || ! $validSize) {
            // TODO: How to handle?
            Log::error('UploadFiles: invalid file type or size', [
                'type' => $type,
                'size' => $size,
            ]);
            // Delete temp file
            Storage::delete($path);

            return false;
        }

        return true;
    }

    public function moveFile($tempPath, $newPath): void
    {
        try {
            Storage::move($tempPath, $newPath);
        } catch (\Exception $e) {
            Log::error('UploadFiles: error moving file', ['exception' => $e]);
        }
    }

    public function createAndQueue($path): void
    {
        // Create the audio file record
        $file = AudioFile::createFromUpload(
            searchId: $this->search->id,
            path: $path,
            originalFilename: $this->file['name'],
            timezone: $this->timezone,
        );

        // Queue the file for processing
        $this->batch()->add(new ProcessFile($this->search, $file, $this->fileCount));
    }
}
