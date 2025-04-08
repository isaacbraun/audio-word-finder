<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use App\Enums\SearchStatus;
use App\Jobs\ProcessFile;

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
     * Create a new search with associated files
     *

     * @param array $searchData Array containing user_id, query, and completion_email
     * @param array $files Array of [UploadedFile, originalFilename] pairs
     * @return static
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
}
