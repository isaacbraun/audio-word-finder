<?php

namespace App\Jobs;

use App\Enums\SearchStatus;
use App\Models\Search;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class CheckBatchStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        protected string $batchId,
        protected Search $search,
        protected int $fileCount
    ) {}

    public function progressHandler(): void
    {
        if ($this->search->status !== SearchStatus::Pending) {
            return; // Skip if we've already moved past uploading
        }

        // Check if all files have been uploaded
        $allUploaded = $this->search->files()->count() === $this->fileCount;

        if ($allUploaded) {
            $this->search->status = SearchStatus::Processing;
            $this->search->save();
        }

        // Re-run this job after a delay
        self::dispatch($this->batchId, $this->search, $this->fileCount)->delay(2);
    }

    public function finishedHanlder(): void
    {
        Log::info('Batch Check Finished');
        // If query found: create report (which calls completeAndEmail())
        if ($this->search->query_total > 0) {
            CreateReport::dispatch($this->search);
        } else {
            $this->search->completeAndEmail();
        }
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $batch = Bus::findBatch($this->batchId);

        if (! $batch) {
            Log::error("Batch not found: {$this->batchId}");
        }

        if ($batch->finished()) {
            $this->finishedHanlder();
        } else {
            $this->progressHandler();
        }
    }
}
