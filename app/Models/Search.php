<?php

namespace App\Models;

use App\Enums\SearchStatus;
use App\Jobs\CreateReport;
use App\Jobs\UploadFiles;
use App\Mail\SearchFinished;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * @param
     */
    public static function createWithFiles(array $searchData, array $fileArray): int
    {
        return DB::transaction(function () use ($searchData, $fileArray) {
            // Create the search entry
            $search = static::create([
                'user_id' => $searchData['user_id'],
                'query' => $searchData['query'],
                'completion_email' => $searchData['completion_email'],
            ]);
            //
            // Get name and path of temporary files
            $tempFiles = collect($fileArray)->map(function ($file) {
                return [
                    'name' => $file->getClientOriginalName(),
                    'path' => $file->getRealPath(),
                ];
            });

            // Upload Files and queue for processing
            UploadFiles::dispatch($search, $tempFiles, Auth::user()->timezone);

            return $search->id;
        });
    }

    /**
     * Check if all the files have bee processed.
     * If so, update Search status and email user
     */
    public function whenFinished(): void
    {
        $filesCount = static::files()->count();
        $processedFilesCount = static::files()->where('transcription_path', '!=', null)->count();

        // Create CSV if all files have been processed
        if ($processedFilesCount === $filesCount) {
            CreateReport::dispatch($this);
        }
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
