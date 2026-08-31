<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic catalog every invoice item is billed against — a course fee, a
 * book, a T-shirt, a certificate, or anything else a school later decides to
 * sell. Adding a new sellable thing is a row here, never a new table or a
 * code change (see docs on InvoiceItem for why there is no
 * `student_course_payment`/`student_book_payment`/... per-product table).
 *
 * `type` is a free-ish string disciplined by App\Support\Billing\ProductType
 * rather than a DB enum/check constraint — the same "constants catalog, not
 * a rigid type" choice this codebase already makes for Program::$level and
 * GalleryImage::$status. It exists for filtering/reporting; nothing in the
 * billing engine branches on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('OTHER');
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
