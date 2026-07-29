<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-owned table. The row-level security block below is the canonical
     * one from docs/architecture.md, inlined verbatim so that editing a shared
     * helper can never change what this applied migration did.
     */
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement('ALTER TABLE projects ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE projects FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON projects
                USING (
                    tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                    OR current_setting('app.tenancy_bypass', true) = '1'
                )
                WITH CHECK (
                    tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
