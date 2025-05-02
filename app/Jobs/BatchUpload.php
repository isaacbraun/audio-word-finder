<?php

namespace App\Jobs;

use App\Models\Search;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class BatchUpload implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Search $search,
        protected array $fileArray,
        protected string $timezone,
    ) {}

    /**
     * Dispatches a batch of file upload jobs for asynchronous processing.
     *
     * Creates and queues an `UploadFile` job for each file in the provided array, passing the associated search model, file, timezone, and total file count. The jobs are dispatched as a batch that allows individual job failures without stopping the entire batch.
     */
    public function handle(): void
    {
        $fileCount = count($this->fileArray);

        $jobs = [];
        foreach ($this->fileArray as $file) {
            $jobs[] = new UploadFile($this->search, $file, $this->timezone, $fileCount);
        }

        Bus::batch($jobs)
            ->allowFailures()
            ->dispatch();
    }
}
