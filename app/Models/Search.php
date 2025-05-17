<?php

namespace App\Models;

use App\Enums\FileStatus;
use App\Enums\SearchStatus;
use App\Jobs\BatchUpload;
use App\Jobs\CreateReport;
use App\Mail\SearchFinished;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class Search extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['query', 'user_id', 'status', 'completion_email'];

    protected $casts = [
        'status' => SearchStatus::class,
    ];

    /**
     * Get the uploaded files for the search
     */
    public function files(): HasMany
    {
        return $this->hasMany(AudioFile::class);
    }

    /**
     * Get the user that owns the search
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Return the search's creation date formatted and in the user's timezone
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at->setTimezone(Auth::user()->timezone ?? 'UTC')->toDayDateTimeString();
    }

    /**
     * Create a new search with associated files
     *
     * @param  array  $searchData  Array containing user_id, query, and completion_email
     * @param  array  $fileArray  Array of [name, path] pairs
     */
    public static function createWithFiles(array $searchData, array $fileArray): int
    {
        if (empty($fileArray)) {
            throw new Exception('No files provided');
        }

        try {
            // Create the search entry
            $search = static::create([
                'user_id' => $searchData['user_id'],
                'query' => $searchData['query'],
                'completion_email' => $searchData['completion_email'],
            ]);

            BatchUpload::dispatch($search, $fileArray, Auth::user()->timezone ?? 'UTC');

            return $search->id;
        } catch (Exception $e) {
            Log::error('Search creation failed', [
                'error' => $e->getMessage(),
                'search_data' => $searchData,
            ]);
            throw $e;
        }
    }

    public function attemptToFinish(bool $retry): void
    {
        // Check if there are NO files with a status NOT equal to the desired status
        $allFilesProcessed = $this->files()
            ->whereIn('status', [FileStatus::Queued, FileStatus::Uploaded])
            ->doesntExist();

        Log::info('All Processed: ' . $allFilesProcessed);

        // If all files have NOT been processed, exit
        if (!$allFilesProcessed) {
            return;
        }

        // Create Report if matches found
        if (!$retry && $this->query_total > 0) {
            CreateReport::dispatch($this);
        }

        $this->status = SearchStatus::Completed;
        $this->save();
        $this->completeAndEmail();
    }

    public function addToQueryCount(int $count): void
    {
        if (isset($this->query_total) && $this->query_total > 0) {
            $this->query_total += intval($count);
        } else {
            $this->query_total = intval($count);
        }

        $this->save();
    }

    /**
     * Set status to completed and send email
     */
    public function completeAndEmail(): void
    {
        $this->status = SearchStatus::Completed;
        $this->save();

        // Send user email of completion
        if ($this->completion_email) {
            Mail::to($this->user)->send(new SearchFinished($this));
        }
    }
}
