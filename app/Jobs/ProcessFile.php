<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Models\Search;
use App\Enums\SearchStatus;
use App\Mail\SearchFinished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
        if (!$this->file->audio_path && !Storage::exists($this->file->audio_path)) {
            throw new \Exception('Audio file does not exist');
        }

        // Get transcription response from Whisper
        $transcription_response = $this->transcribeWithWhisper($this->file->audio_path);

        // Search for query and format
        $matches_json = $this->findAndSegment($transcription_response, $this->search->query);

        // Check if the query response is valid
        if ($matches_json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON returned: ' . json_last_error_msg());
        }

        // Check if the matchCount key exists
        if (!isset($matches_json['matchCount'])) {
            throw new \Exception('matchCount not found in JSON response');
        }

        // Store transcription response to file
        $transcription_path = 'transcriptions/' . Str::uuid()->toString() . '.json';
        Storage::put($transcription_path, json_encode($matches_json));

        // Update File Model
        $this->file->query_count = $matches_json['matchCount'];
        $this->file->transcription_path = $transcription_path;

        // Update Search Model Query Total
        if ($this->search->query_total) {
            $this->search->query_total += intval($matches_json['matchCount']);
        } else {
            $this->search->query_total = intval($matches_json['matchCount']);
        }

        // Save DB changes
        $this->search->save();
        $this->file->save();

        $this->search->fresh();
        // Check search status
        $this->whenFinished();
    }

    /**
     * The job failed to process.
     *
     * @param \Exception $exception
     *
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        // Set transcription_path to 'failed' as pseudo status
        $this->file->transcription_path = 'failed';
        $this->file->save();

        // Check Search status
        $this->whenFinished();
        $this->search->save();

        if (app()->bound('sentry')) {
            app('sentry')->captureException($exception);
        } else {
            Log::warning('Sentry not bound');
        }
    }

    /**
     * Check if all the files have bee processed.
     * If so, update Search status and email user
     */
    protected function whenFinished(): void
    {
        // Check if all files have been processed
        $processedFilesCount = $this->search->files()->where('transcription_path', '!=', null)->count();
        if ($processedFilesCount === $this->search->files()->count()) {
            $this->search->status = SearchStatus::Completed;
            $this->search->save();

            // Send user email of completion
            if ($this->search->completion_email) {
                Mail::to($this->search->user)->send(new SearchFinished($this->search));
            }
        }
    }

    /**
     * Transcribe audio file using OpenAI's Whisper API.
     *
     * @param string $audioFilePath Path to the audio file
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
                    'response_format' => 'json'
                ]);

            // Close the stream
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (!$response->successful()) {
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
     * @param string $text The text to search within
     * @param string $searchString The string to search for
     * @return array The result with match count and segments
     */
    protected function findAndSegment(string $text, string $searchString): array
    {
        $result = [
            'matchCount' => 0,
            'segments' => []
        ];

        if (empty($searchString) || empty($text)) {
            $result['segments'][] = [
                'match' => false,
                'text' => $text
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
                        'text' => substr($text, $currentPosition)
                    ];
                }
                break;
            }

            // Add the text before the match as a non-matching segment
            if ($nextMatchPosition > $currentPosition) {
                $result['segments'][] = [
                    'match' => false,
                    'text' => substr($text, $currentPosition, $nextMatchPosition - $currentPosition)
                ];
            }

            // Add the matching segment
            $result['segments'][] = [
                'match' => true,
                'text' => substr($text, $nextMatchPosition, $searchLen)
            ];

            // Increment the match count
            $result['matchCount']++;

            // Move the current position past this match
            $currentPosition = $nextMatchPosition + $searchLen;
        }

        return $result;
    }
}
