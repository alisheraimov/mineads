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
        Schema::table('ad_creatives', function (Blueprint $table) {
            $table->timestamp('review_submitted_at')->nullable()->index();
            $table->string('rejection_code')->nullable();
            $table->text('rejection_explanation')->nullable();
        });
        DB::table('ad_creatives')->update(['review_submitted_at' => DB::raw('created_at')]);
        Schema::create('admin_creative_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_id')->constrained('ad_creatives');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['creative_id', 'created_at']);
        });
        $roles = Role::whereIn('name', ['admin-owner', 'ad-ops', 'support'])->where('guard_name', 'web')->get();
        if ($roles->isNotEmpty()) {
            $permission = Permission::firstOrCreate(['name' => 'admin.creatives.view', 'guard_name' => 'web']);
            foreach ($roles as $role) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'admin.creatives.view')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Schema::dropIfExists('admin_creative_events');
        Schema::table('ad_creatives', fn (Blueprint $table) => $table->dropColumn(['review_submitted_at', 'rejection_code', 'rejection_explanation']));
    }
};
