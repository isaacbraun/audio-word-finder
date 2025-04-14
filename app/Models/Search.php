<?php

namespace App\Models;

use App\Enums\SearchStatus;
use App\Jobs\CreateReport;
use App\Jobs\UploadFile;
use App\Mail\SearchFinished;
use Exception;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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

        return DB::transaction(function () use ($searchData, $fileArray) {
            try {
                // Create the search entry
                $search = static::create([
                    'user_id' => $searchData['user_id'],
                    'query' => $searchData['query'],
                    'completion_email' => $searchData['completion_email'],
                ]);

                $jobs = [];
                // Create file upload queue jobs
                foreach ($fileArray as $file) {
                    $jobs[] = new UploadFile($search, $file, Auth::user()->timezone ?? 'UTC');
                }

                Bus::batch($jobs)->allowFailures()
                    ->progress(function () use ($search, $fileArray) {
                        if ($search->status !== SearchStatus::Pending) {
                            return; // Skip if we've already moved past uploading
                        }

                        $allUploaded = $search->files()->count() === count($fileArray);

                        if ($allUploaded) {
                            $search->status = SearchStatus::Processing;
                            $search->save();
                        }
                    })
                    ->finally(function (Batch $batch) use ($search) {
                        if ($batch->hasFailures()) {
                            $search->update(['status' => SearchStatus::Failed]);

                            return;
                        }

                        if ($search->query_total > 0) {
                            CreateReport::dispatch($search);
                        } else {
                            $search->completeAndEmail();
                        }
                    })
                    ->dispatch();

                return $search->id;
            } catch (Exception $e) {
                Log::error('Search creation failed', [
                    'error' => $e->getMessage(),
                    'search_data' => $searchData,
                ]);
                throw $e;
            }
        });
    }

    /**
     * Set status to completed and send email
     */
    public function completeAndEmail(): void
    {
        $this->status = SearchStatus::Completed;
        static::save();

        // Send user email of completion
        if ($this->completion_email) {
            Mail::to($this->user)->send(new SearchFinished($this));
        }
    }
}
