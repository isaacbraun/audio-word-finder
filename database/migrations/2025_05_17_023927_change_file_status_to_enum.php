<?php

use App\Enums\FileStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get an array of the backing values from your enum cases
        $statusValues = array_column(FileStatus::cases(), 'value');

        Schema::table('files', function (Blueprint $table) use ($statusValues) {
            $table->enum('status', $statusValues)->default(FileStatus::Queued->value)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enum', function (Blueprint $table) {
            $table->string('status')->default(FileStatus::Queued->value)->change();
        });
    }
};
