<?php

namespace App\Jobs;

use App\Enums\SearchStatus;
use App\Models\AudioFile;
use App\Models\Search;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Search $search,
        /**
         * @var collection<array{name: string, path: string}>
         */
        protected Collection $temporaryFiles,
        protected string $timezone,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function () {
            foreach ($this->temporaryFiles as $fileInfo) {
                $filename = Str::after($fileInfo['path'], 'livewire-tmp/');
                $tempPath = "livewire-tmp/{$filename}";
                $newPath = "audioFiles/{$filename}";

                try {
                    Storage::move($tempPath, $newPath);
                } catch (\Exception $e) {
                    Log::error('UploadFiles: error moving file', ['exception' => $e]);
                }

                // Create the audio file record
                $file = AudioFile::createFromUpload(
                    searchId: $this->search->id,
                    path: $newPath,
                    originalFilename: $fileInfo['name'],
                    timezone: $this->timezone,
                );

                // Queue the file for processing
                ProcessFile::dispatch($this->search, $file);
            }

        });

        $this->search->status = SearchStatus::Processing;
        $this->search->save();
    }
}
