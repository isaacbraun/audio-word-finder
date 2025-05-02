<?php

namespace App\Jobs;

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
     * Initializes a new UploadFile job with the given search, file data, timezone, and total file count.
     *
     * @param Search $search The Search model associated with the upload.
     * @param array $file The uploaded file data.
     * @param string $timezone The timezone for processing the file.
     * @param int $fileCount The total number of files in the batch.
     */
    public function __construct(
        protected Search $search,
        protected array $file,
        protected string $timezone,
        protected int $fileCount,
    ) {}

    /**
     * Handles the upload and initial processing of an audio file in a queued batch job.
     *
     * Validates the uploaded file, moves it to permanent storage, creates a database record, queues further processing, and updates the associated search status.
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
        if (! Storage::exists($tempPath)) {
            // TODO: how should this be handled? Show error for file?
            Log::error('UploadFiles: file not found', ['path' => $tempPath]);
        }

        if ($this->validateFile($tempPath)) {
            $this->moveFile($tempPath, $newPath);
            $this->createAndQueue($newPath);
            $this->updateStatus();
        }
    }

    /**
     * Updates the status of the associated Search model based on the number of processed files.
     *
     * If all files in the batch have been uploaded, sets the Search status to Processing and saves the model.
     * Otherwise, logs the current upload progress.
     */
    public function updateStatus(): void
    {
        if ($this->batch()->processedJobs() + 1 >= $this->fileCount) {
            $this->search->status = SearchStatus::Processing;
            $this->search->save();
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

    /**
     * Creates an audio file record from the uploaded file and adds a processing job to the batch queue.
     *
     * @param string $path The storage path of the uploaded audio file.
     */
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
