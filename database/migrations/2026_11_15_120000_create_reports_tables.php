<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            // Only a SHA-256 of the tracking code is stored, the plain code is shown once to the reporter.
            $table->string('tracking_hash', 64)->unique();
            $table->string('tracking_hint', 8); // last 4 chars, shown to staff for phone follow-ups
            $table->string('title', 200);
            $table->text('description');
            $table->string('category', 50)->index();
            $table->text('contact')->nullable(); // encrypted
            $table->string('status', 20)->default('submitted')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('report_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('original_name', 191);
            $table->string('stored_path', 255);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->timestamps();
        });

        Schema::create('report_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_status_histories');
        Schema::dropIfExists('report_attachments');
        Schema::dropIfExists('reports');
    }
};
