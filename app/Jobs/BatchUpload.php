<?php

namespace App\Jobs;

use App\Enums\SearchStatus;
use App\Models\Search;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class BatchUpload implements ShouldQueue
{
    use Queueable;

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
        $jobs = [];
        foreach ($this->fileArray as $file) {
            $jobs[] = new UploadFile($this->search, $file, $this->timezone);
        }

        Bus::batch($jobs)->allowFailures()
            ->progress(function () {
                if ($this->search->status !== SearchStatus::Pending) {
                    return; // Skip if we've already moved past uploading
                }

                $allUploaded = $this->search->files()->count() === count($this->fileArray);

                if ($allUploaded) {
                    $this->search->status = SearchStatus::Processing;
                    $this->search->save();
                }
            })
            ->finally(function () {
                if ($this->search->query_total > 0) {
                    CreateReport::dispatch($this->search);
                } else {
                    $this->search->completeAndEmail();
                }
            })
            ->dispatch();
    }
}
