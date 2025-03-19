<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    protected $fillable = ['audio_path', 'audio_filename', 'query_count', 'parsed_date'];

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
}
