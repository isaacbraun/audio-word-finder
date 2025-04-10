<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use App\Models\Search;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CreateReport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Search $search,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $path = $this->createCsv();
        } catch (\Exception $e) {
            Log::error('REPORT: ' . $e->getMessage());
            $this->search->completeAndEmail();
        }

        $this->search->report_path = $path;
        $this->search->save();

        $this->search->completeAndEmail();
    }

    protected function createCsv(): string
    {
        $files = $this->search->files()
            ->where('query_count', '>', 0)
            ->orderBy('query_count', 'desc')
            ->get();

        $report = "File,Matches\n";

        if (count($files) === 0) {
            throw new \Exception('No files found');
        }

        foreach ($files as $file) {
            $name = $file->audio_filename;

            if ($file->parsed_date) {
                $name = $file->parsed_date->toDayDateTimeString();
            }

            $report .= "\"{$name}\",{$file->query_count}\n";
        }

        // Store CSV
        $path = 'reports/' . Str::uuid()->toString() . '.csv';

        if (!Storage::put($path, $report)) {
            throw new \Exception('Failed to store CSV');
        }

        return $path;
    }
}
