<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

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
    protected $fillable = ['search_id', 'audio_path', 'audio_filename', 'query_count', 'parsed_date'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parsed_date' => 'datetime',
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
     * Create a new AudioFile from an uploaded file
     *
     * @param UploadedFile $uploadedFile
     * @param string $originalFilename
     * @return static
     */
    public static function createFromUpload(
        int $searchId,
        UploadedFile $uploadedFile,
        string $originalFilename
    ): static {
        // Store the file
        $path = $uploadedFile->store('audioFiles');

        // Sanitize the filename
        $sanitizedName = static::sanitizeFilename($originalFilename);

        // Create and return the new AudioFile
        return static::create([
            'search_id' => $searchId,
            'audio_path' => $path,
            'audio_filename' => $sanitizedName,
            'parsed_date' => static::parseDate($originalFilename),
        ]);
    }

    /**
     * Sanitize a filename
     * @param string $filename
     * @return string
     */
    protected static function sanitizeFilename(string $filename): string
    {
        $sanitized = preg_replace('/[^\w\-\.\s]/', '', $filename);
        return substr($sanitized, 0, 255);
    }

    /**
     * Parse the date from the filename
     * @param string $filename
     * @return Carbon|null
     */
    protected static function parseDate(string $filename): Carbon | null
    {
        // Strict regex that only matches the WSMC skimmer format
        $pattern = "/(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})/";

        if (!preg_match($pattern, $filename, $matches)) {
            // Return null
            return null;
        }

        try {
            $dateString = $matches[1]; // 2025-02-17
            $timeString = str_replace('-', ':', $matches[2]); // Convert 14-25-13 to 14:25:13

            // Create Carbon instance with validation
            $dateTime = Carbon::createFromFormat('Y-m-d H:i:s', "$dateString $timeString");

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
