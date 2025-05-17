<?php

namespace App\Models;

use App\Enums\FileStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AudioFile extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'files';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['search_id', 'audio_path', 'audio_filename', 'query_count', 'parsed_date', 'status'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parsed_date' => 'datetime',
            'status' => FileStatus::class,
        ];
    }

    /**
     * Get the Search that owns the file
     */
    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /**
     * Return the files's parsed date formatted and in the user's timezone
     */
    public function getFormattedParsedDateAttribute(): string
    {
        return $this->parsed_date->setTimezone(Auth::user()->timezone ?? 'UTC')->toDayDateTimeString();
    }

    /**
     * Retrieves the transcription data for the audio file.
     *
     * Attempts to load and return the transcription as an array from the file specified by the `transcription_path` attribute. If the transcription file is missing, empty, or cannot be loaded, updates the file's status to `TranscriptionMissing` and returns an empty array.
     *
     * @return array The transcription data, or an empty array if unavailable.
     */
    public function getTranscription(): array
    {
        if (!$this->transcription_path) {
            return [];
        }

        try {
            // Load transcription from storage
            $transcription = Storage::json($this->transcription_path);
            if (! $transcription) {
                // Set status to transcription-missing
                $this->status = FileStatus::TranscriptionMissing;
                $this->save();

                return [];
            } else {
                return $transcription;
            }
        } catch (\Exception $e) {
            Log::error('getTranscription: error loading transcription', ['exception' => $e->getMessage()]);
            return [];
        }
    }

    /****
     * Creates and saves a new AudioFile record from an uploaded file.
     *
     * Initializes the audio file with a sanitized filename, parses the date from the original filename using the provided timezone, and sets the status to Uploaded.
     *
     * @param int $searchId The ID of the related search.
     * @param string $path The storage path of the uploaded audio file.
     * @param string $originalFilename The original name of the uploaded file.
     * @param string $timezone The timezone used for parsing the date from the filename.
     * @return static The newly created AudioFile instance.
     */
    public static function createFromUpload(
        int $searchId,
        string $path,
        string $originalFilename,
        string $timezone,
    ): static {
        // Sanitize the filename
        $sanitizedName = static::sanitizeFilename($originalFilename);

        // Create and return the new AudioFile
        return static::create([
            'search_id' => $searchId,
            'audio_path' => $path,
            'audio_filename' => $sanitizedName,
            'parsed_date' => static::parseDate($originalFilename, $timezone),
            'status' => FileStatus::Uploaded,
        ]);
    }

    /**
     * Sanitize a filename
     */
    protected static function sanitizeFilename(string $filename): string
    {
        $sanitized = preg_replace('/[^\w\-\.\s]/', '', $filename);

        return substr($sanitized, 0, 255);
    }

    /****
     * Extracts and parses a UTC datetime from a filename using the WSMC skimmer format.
     *
     * Attempts to find a date and time in the filename matching the pattern 'YYYY-MM-DD_HH-MM-SS',
     * converts it to a Carbon instance in UTC, and validates that the date is within a reasonable range
     * (not older than 5 years or more than 1 year in the future). Returns null if parsing fails or the date is out of range.
     *
     * @param string $filename The filename containing the date and time.
     * @param string $timezone The timezone to interpret the extracted date and time.
     * @return Carbon|null The parsed UTC datetime, or null if extraction or validation fails.
     */
    protected static function parseDate(string $filename, string $timezone): ?Carbon
    {
        // Strict regex that only matches the WSMC skimmer format
        $pattern = "/(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})/";

        if (! preg_match($pattern, $filename, $matches)) {
            // Return null
            return null;
        }

        try {
            $dateString = $matches[1]; // 2025-02-17
            $timeString = str_replace('-', ':', $matches[2]); // Convert 14-25-13 to 14:25:13

            // Create Carbon instance with validation
            $dateTime = Carbon::createFromFormat('Y-m-d H:i:s', "$dateString $timeString", $timezone)->setTimezone('UTC');

            // Additional validation - ensure the date is reasonable
            $now = Carbon::now();
            $minDate = $now->copy()->subYears(5);
            $maxDate = $now->copy()->addYears(1);

            if ($dateTime->lt($minDate) || $dateTime->gt($maxDate)) {
                // Date is suspiciously old or in the future
                throw new \Exception('Filename date out of reasonable range: ' . $dateTime->toDateTimeString());
            }

            return $dateTime;
        } catch (\Exception $e) {
            // Handle date parsing errors
            Log::info('New Search: filename date parsing issue', ['exception' => $e->getMessage()]);

            return null;
        }
    }
}
