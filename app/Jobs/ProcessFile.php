<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Models\Search;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessFile implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Search $search,
        public AudioFile $file
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // If audio file doesn't exist
        if (!$this->file->audio_path) {
            throw new \Exception('Audio file does not exist');
        }

        // Get full path to file
        $audioPath = Storage::path($this->file->audio_path);

        // Run whisper command through process
        $transcriptionProcess = Process::path(__DIR__)->run(['python3', '../../scripts/transcribe.py', $audioPath, $this->search->query])->throw();
        $result = $transcriptionProcess->output();

        // Store transcription file
        $transcriptionPath = 'transcriptions/' . Str::uuid()->toString() . '.json';
        Storage::put($transcriptionPath, $result);

        // Decode the JSON and store the result
        $jsonData = json_decode($result, true);

        // Check if the JSON was decoded successfully
        if ($jsonData === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON returned: ' . json_last_error_msg());
        }

        // Check if the matchCount key exists
        if (!isset($jsonData['matchCount'])) {
            throw new \Exception('matchCount not found in JSON response');
        }

        // Write query count to DB file entry
        $this->file->query_count = $jsonData['matchCount'];
        // Write transcription path to DB file entry
        $this->file->transcription_path = $transcriptionPath;
        // Update query count in DB search entry
        if ($this->search->query_total) {
            $this->search->query_total += intval($jsonData['matchCount']);
        } else {
            $this->search->query_total = intval($jsonData['matchCount']);
        }
        // Save DB changes
        $this->search->save();
        $this->file->save();
    }
}
