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
        Schema::table('files', function (Blueprint $table) {
            $table->enum('status', FileStatus::cases())->default(FileStatus::Queued->value)->change();
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
