<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /****
     * Updates the 'status' column in the 'files' table to a string type with a default value of 'Queued'.
     *
     * Alters the existing 'status' column to use the string value of the 'Queued' status from the FileStatus enum as its default.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('status')->default(App\Enums\FileStatus::Queued->value)->change();
        });
    }

    /**
     * Reverts the `status` column in the `files` table to a string with a default value of 'processing'.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Revert to previous column definition
            $table->string('status')->default('processing')->change();
        });
    }
};
