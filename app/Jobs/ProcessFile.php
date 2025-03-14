<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Models\Search;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
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
        // Get full path to file
        // $audioPath = Storage::path($this->file->audio_path);
        //
        // // Run whisper command through process
        // $transcriptionProcess = Process::path(__DIR__)->run(['python3', '../../scripts/transcribe.py', $audioPath, $this->search->query])->throw();
        // $result = $transcriptionProcess->output();

        // If audio file doesn't exist
        if (!$this->file->audio_path && !Storage::exists($this->file->audio_path)) {
            throw new \Exception('Audio file does not exist');
        }

        // Get transcription response from Whisper
        $transcription_response = $this->transcribeWithWhisper($this->file->audio_path);

        // Search for query and format
        $matches_json = $this->findAndSegment($transcription_response, $this->search->query);

        // Store transcription response to file
        $transcription_path = 'transcriptions/' . Str::uuid()->toString() . '.json';
        Storage::put($transcription_path, $matches_json);

        // Decode the JSON and store the result
        // $matches_json = json_decode($result, true);

        // Check if the JSON was decoded successfully
        if ($matches_json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON returned: ' . json_last_error_msg());
        }

        // Check if the matchCount key exists
        if (!isset($matches_json['matchCount'])) {
            throw new \Exception('matchCount not found in JSON response');
        }

        // Write query count to DB file entry
        $this->file->query_count = $matches_json['matchCount'];
        // Write transcription path to DB file entry
        $this->file->transcription_path = $transcription_path;
        // Update query count in DB search entry
        if ($this->search->query_total) {
            $this->search->query_total += intval($matches_json['matchCount']);
        } else {
            $this->search->query_total = intval($matches_json['matchCount']);
        }
        // Save DB changes
        $this->search->save();
        $this->file->save();
    }

    /**
     * Transcribe audio file using OpenAI's Whisper API.
     *
     * @param string $audioFilePath Path to the audio file
     * @return string The transcribed text
     */
    protected function transcribeWithWhisper(string $audio_path): string
    {
        $file = Storage::get($audio_path);

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->attach(
                'file',
                $file,
            )
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'whisper-1',
                'language' => 'en', // Optional: might speed things up
                'response_format' => 'json'
            ]);

        if (!$response->successful()) {
            throw new \Exception('Whisper API error: ' . $response->body());
        }

        $result = $response->json();
        return $result['text'] ?? '';
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
