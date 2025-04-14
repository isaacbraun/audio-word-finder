<?php

namespace App\Models;

use App\Enums\SearchStatus;
use App\Jobs\CreateReport;
use App\Jobs\ProcessFile;
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
        return $this->created_at->setTimezone(Auth::user()->timezone)->toDayDateTimeString();
    }

    /**
     * Create a new search with associated files
     *

     * @param  array  $searchData  Array containing user_id, query, and completion_email
     * @param  array  $files  Array of [UploadedFile, originalFilename] pairs
     */
    public static function createWithFiles(array $searchData, array $files): static
    {
        return DB::transaction(function () use ($searchData, $files) {
            // Create the search entry
            $search = static::create([
                'user_id' => $searchData['user_id'],
                'query' => $searchData['query'],
                'status' => SearchStatus::Processing,
                'completion_email' => $searchData['completion_email'],
            ]);

            // Process each file
            $fileEntries = collect($files)->map(function ($file) use ($search) {
                return AudioFile::createFromUpload(
                    searchId: $search->id,
                    uploadedFile: $file,
                    originalFilename: $file->getClientOriginalName()
                );
            })->all();

            // Dispatch processing jobs
            foreach ($fileEntries as $fileEntry) {
                ProcessFile::dispatch($search, $fileEntry);
            }

            return $search;
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
