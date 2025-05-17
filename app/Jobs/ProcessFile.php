<?php

namespace App\Jobs;

use App\Enums\FileStatus;
use App\Enums\SearchStatus;
use App\Models\AudioFile;
use App\Models\Search;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessFile implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Search $search,
        public AudioFile $file,
        public bool $retry = false,
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

        // Update Search Status if necessary
        if ($this->search->status !== SearchStatus::Processing) {
            $this->search->status = SearchStatus::Processing;
            $this->search->save();
        }

        // If audio file doesn't exist
        if (! $this->file->audio_path || ! Storage::exists($this->file->audio_path)) {
            throw new \Exception('Audio file does not exist');
        }

        // Process File
        try {
            [$transcription_path, $match_count] = $this->processAndStore();

            // Update File Model
            $this->file->query_count = $match_count;
            $this->file->transcription_path = $transcription_path;
            $this->file->status = FileStatus::Transcribed;
            $this->file->save();

            // Update query total
            $this->search->addToQueryCount($match_count);

            // Call Finished Check
            $this->search->attemptToFinish($this->retry);
        } catch (\Exception $e) {
            $this->file->status = FileStatus::Failed;
            $this->file->save();
            throw new \Exception('Error processing file: ' . $e->getMessage());
        }
    }

    /**
     * The job failed to process.
     *
     * @param  \Exception  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        $this->file->status = FileStatus::Failed;
        $this->file->save();

        if (app()->bound('sentry')) {
            app('sentry')->captureException($exception);
        } else {
            Log::warning('Sentry not bound');
        }
    }

    protected function processAndStore(): array
    {
        // Get transcription response from Whisper
        $transcription_response = $this->transcribeWithWhisper($this->file->audio_path);

        // Search for query and format
        $matches_json = $this->findAndSegment($transcription_response, $this->search->query);

        // Check if the matchCount key exists
        if (! isset($matches_json['matchCount'])) {
            throw new \Exception('matchCount not found in JSON response');
        }

        // Store search response to file
        $transcription_path = 'transcriptions/' . Str::uuid()->toString() . '.json';

        try {
            Storage::put($transcription_path, json_encode($matches_json));

            return [$transcription_path, $matches_json['matchCount']];
        } catch (\Exception $e) {
            // Let the caller decide how to mark failure
            throw new \Exception(
                'Error writing transcription to storage: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Transcribe audio file using OpenAI's Whisper API.
     *
     * @param  string  $audioFilePath  Path to the audio file
     * @return string The transcribed text
     */
    protected function transcribeWithWhisper(string $audio_path): string
    {
        // Get a stream resource for the file
        $stream = Storage::readStream($audio_path);

        // Get the original filename or create one
        $filename = basename($audio_path);

        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->attach(
                    'file',
                    $stream,
                    $filename
                )
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'en',
                    'response_format' => 'json',
                ]);

            // Close the stream
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $response->successful()) {
                throw new \Exception('Whisper API error: ' . $response->body());
            }

            $result = $response->json();

            return $result['text'] ?? '';
        } catch (\Exception $e) {
            // Make sure we clean up even if there's an error
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw $e;
        }
    }

    /**
     * Find all occurrences of a search string in text and segment the text.
     *
     * @param  string  $text  The text to search within
     * @param  string  $searchString  The string to search for
     * @return array The result with match count and segments
     */
    protected function findAndSegment(string $text, string $searchString): array
    {
        $result = [
            'matchCount' => 0,
            'segments' => [],
            'fullText' => $text,
        ];

        if (empty($searchString) || empty($text)) {
            $result['segments'][] = [
                'match' => false,
                'text' => $text,
            ];

            return $result;
        }

        $currentPosition = 0;
        $searchLen = strlen($searchString);
        $textLen = strlen($text);

        while ($currentPosition <= $textLen) {
            // Find the next occurrence of the search string
            $nextMatchPosition = stripos($text, $searchString, $currentPosition); // Using case-insensitive search

            // If no more matches are found
            if ($nextMatchPosition === false) {
                // Add the remaining text as a non-matching segment
                if ($currentPosition < $textLen) {
                    $result['segments'][] = [
                        'match' => false,
                        'text' => substr($text, $currentPosition),
                    ];
                }
                break;
            }

            // Add the text before the match as a non-matching segment
            if ($nextMatchPosition > $currentPosition) {
                $result['segments'][] = [
                    'match' => false,
                    'text' => substr($text, $currentPosition, $nextMatchPosition - $currentPosition),
                ];
            }

            // Add the matching segment
            $result['segments'][] = [
                'match' => true,
                'text' => substr($text, $nextMatchPosition, $searchLen),
            ];

            // Increment the match count
            $result['matchCount']++;

            // Move the current position past this match
            $currentPosition = $nextMatchPosition + $searchLen;
        }

        return $result;
    }
}
