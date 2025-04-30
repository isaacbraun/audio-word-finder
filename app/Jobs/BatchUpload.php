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
     * Execute the job.
     */
    public function handle(): void
    {
        $fileCount = count($this->fileArray);

        $jobs = [];
        foreach ($this->fileArray as $file) {
            $jobs[] = new UploadFile($this->search, $file, $this->timezone);
        }

        $batch = Bus::batch($jobs)
            ->allowFailures()
            ->dispatch();

        CheckBatchStatus::dispatch($batch->id, $this->search, $fileCount);
    }
}
