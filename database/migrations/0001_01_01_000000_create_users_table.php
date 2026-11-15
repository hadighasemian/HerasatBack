<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Username = personnel code (کد پرسنلی)
            $table->string('personnel_code', 20)->unique();
            // National code is stored encrypted (APP_KEY) plus a keyed HMAC blind index for uniqueness/lookups.
            $table->text('national_code');
            $table->string('national_code_hash', 64)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('father_name', 100)->nullable();
            $table->string('employment_type', 100)->nullable();
            $table->string('department', 191)->nullable();
            $table->string('work_location', 191)->nullable();
            $table->text('phone')->nullable(); // encrypted
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
