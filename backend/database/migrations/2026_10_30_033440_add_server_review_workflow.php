<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('review_status')->default('awaiting_activation')->index();
            $table->timestamp('review_submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->boolean('legacy_approval')->default(false);
            $table->string('status_before_suspension')->nullable();
            $table->text('suspension_reason')->nullable();
        });
        DB::table('servers')->whereNotNull('activated_at')->update(['review_status' => 'approved', 'legacy_approval' => true]);
        Schema::create('server_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('action');
            $table->json('details');
            $table->timestamps();
            $table->index(['server_id', 'created_at']);
        });
        foreach (['admin.servers.view' => ['admin-owner', 'ad-ops', 'support', 'finance'], 'admin.servers.manage' => ['admin-owner', 'ad-ops']] as $name => $names) {
            $roles = Role::whereIn('name', $names)->where('guard_name', 'web')->get();
            if ($roles->isNotEmpty()) {
                $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                foreach ($roles as $role) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('server_review_events');
        Schema::table('servers', fn (Blueprint $table) => $table->dropIndex(['review_status']));
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn(['review_status', 'review_submitted_at', 'reviewed_at', 'review_reason', 'legacy_approval', 'status_before_suspension', 'suspension_reason']));
        Permission::whereIn('name', ['admin.servers.view', 'admin.servers.manage'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
