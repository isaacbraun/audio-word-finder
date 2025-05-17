<?php

namespace App\Console\Commands;

use App\Enums\FileStatus;
use App\Models\AudioFile;
use Illuminate\Console\Command;
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

    /****
     * Updates the status of all audio files based on their current status, transcription, and audio file presence.
     *
     * Iterates through all `AudioFile` records, validates their status against the `FileStatus` enum, and updates the status according to the existence and validity of associated transcription and audio files. Outputs a success message upon completion.
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
                // Set status to 'queued' using the enum case
                $file->status = FileStatus::Queued;
            }

            // Check if transcription_path is set
            elseif ($file->transcription_path) {
                // Load transcription from storage
                $transcription = Storage::json($file->transcription_path);
                // If transcription is empty, set status to transcription-missing
                if (! $transcription) {
                    $file->status = FileStatus::TranscriptionMissing;
                } else {
                    $file->status = FileStatus::Transcribed;
                }
            }

            // Check if audio_path is set
            elseif ($file->audio_path) {
                // Check if file exists
                if (! Storage::exists($file->audio_path)) {
                    // Set status to 'failed'
                    $file->status = FileStatus::Failed;
                } else {
                    // Set status to 'uploaded'
                    $file->status = FileStatus::Uploaded;
                }
            } else {
                // Set status to 'failed'
                $file->status = FileStatus::Failed;
            }

            // Save the file
            $file->save();
        }

        $this->info('File statuses updated successfully.');
    }
}
