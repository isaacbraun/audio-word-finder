<?php

namespace App\Console\Commands;

use App\Enums\FileStatus;
use App\Models\AudioFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UpdateFileStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-file-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the File Status to new enums deriving state from stored info';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Interate through all files
        $files = AudioFile::all();

        // Get an array of valid enum values
        $validEnumValues = array_column(FileStatus::cases(), 'value');

        foreach ($files as $file) {
            // Get the raw status value from the database
            $rawStatus = $file->getRawOriginal('status');

            // Check if the raw status is not a valid enum value
            if (!in_array($rawStatus, $validEnumValues)) {
                Log::error('UpdateFileStatus: invalid status value', ['value' => $rawStatus]);
                // Set status to 'queued' using the enum case
                $file->status = FileStatus::Queued;
            }

            // Check if transcription_path is set
            elseif ($file->transcription_path) {
                // Load transcription from storage
                $transcription = Storage::json($file->transcription_path);
                // If transcription is empty, set status to transcription-missing
                if (! $transcription) {
                    Log::info('UpdateFileStatus: transcription missing', ['path' => $file->transcription_path]);
                    $file->status = FileStatus::TranscriptionMissing;
                }

                Log::info('UpdateFileStatus: transcription found', ['path' => $file->transcription_path]);
                $file->status = FileStatus::Transcribed;
            }

            // Check if audio_path is set
            elseif ($file->audio_path) {
                // Check if file exists
                if (! Storage::exists($file->audio_path)) {
                    Log::info('UpdateFileStatus: audio file not found', ['path' => $file->audio_path]);
                    // Set status to 'failed'
                    $file->status = FileStatus::Failed;
                } else {
                    Log::info('UpdateFileStatus: audio uploaded', ['path' => $file->audio_path]);
                    // Set status to 'uploaded'
                    $file->status = FileStatus::Uploaded;
                }
            } else {
                Log::info('UpdateFileStatus: audio file not found', ['path' => $file->audio_path]);
                // Set status to 'failed'
                $file->status = FileStatus::Failed;
            }

            // Save the file
            $file->save();
        }

        $this->info('File statuses updated successfully.');
    }
}
