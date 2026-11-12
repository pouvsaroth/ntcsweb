<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Marketing > Campaigns: one ad, flyer, event ... with its
 * channel (Base Data MARKETING_CHANNEL), dates, budget and real cost.
 * An inquiry picks the campaign that brought it (inquiries.marketing_campaign_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->nullable()->unique(); // C00001, from the id
            $table->string('name');
            $table->string('channel', 50); // a MARKETING_CHANNEL Base Data code
            $table->date('start_on');
            $table->date('end_on')->nullable();
            $table->string('currency', 3)->default('USD'); // USD | KHR
            $table->decimal('budget', 15, 2)->nullable();
            $table->decimal('spent', 15, 2)->nullable(); // the real cost so far
            $table->foreignId('academic_program_id')->nullable()->constrained('academic_programs')->nullOnDelete();
            $table->string('target', 255)->nullable(); // who it is for, in words
            $table->string('status', 20)->default('planned'); // planned | active | completed | cancelled
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('channel');
            $table->index('start_on');
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->foreignId('marketing_campaign_id')->nullable()->after('source')->constrained('marketing_campaigns')->nullOnDelete();
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'marketing_campaigns');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketing_campaign_id');
        });
        Schema::dropIfExists('marketing_campaigns');
    }
};
