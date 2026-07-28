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
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE memberships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE memberships FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON memberships
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
        Schema::dropIfExists('memberships');
    }
};
