<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service sign-ups from the website (docs/backoffice/stage-8-signup.md, 8b):
 * what the visitor filled in, until it becomes a school. A free trial becomes
 * a school at once; a paid plan waits for its payment (8c).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bo_signups', function (Blueprint $table) {
            $table->id();
            // The visitor's handle on their own sign-up (status page); never the id.
            $table->uuid('token')->unique();
            $table->string('kind', 10); // trial | paid
            $table->foreignId('plan_id')->nullable()->constrained('bo_plans')->nullOnDelete();
            $table->unsignedSmallInteger('periods')->nullable(); // paid: how many plan periods
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('school_name');
            $table->string('school_name_en')->nullable();
            $table->string('slug', 40);
            $table->string('owner_name');
            $table->string('owner_email');
            $table->string('owner_phone', 50);
            // Hashed; cleared once the school is created.
            $table->string('owner_password')->nullable();
            $table->string('locale', 5)->default('km');
            // pending_payment → paid → created · expired · failed
            $table->string('status', 20);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            // An unpaid sign-up holds its web address until then.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('failure')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['slug', 'status']);
            $table->index(['owner_email', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bo_signups');
    }
};
