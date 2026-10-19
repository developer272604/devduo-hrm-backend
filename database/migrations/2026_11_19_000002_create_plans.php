<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-level catalogue (not tenant scoped): what each monthly plan costs and which modules it unlocks.
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 80);
            $t->text('description')->nullable();
            $t->decimal('monthly_price', 10, 2)->default(0);
            $t->string('currency', 3)->default('INR');
            $t->json('modules');
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });
        // Starting point only: the owner edits names, prices and modules in the Owner panel.
        $core = ['work', 'notifications', 'people', 'attendance', 'leave'];
        $rows = [
            ['starter', 'Starter', 'Core HR: people, attendance and leave.', 999, $core],
            ['growth', 'Growth', 'Starter plus payroll, recruitment, onboarding and performance.', 2499, array_merge($core, ['payroll', 'recruitment', 'lifecycle', 'performance', 'learning', 'experience'])],
            ['enterprise', 'Enterprise', 'Every module, including analytics, workflows, integrations and security.', 4999, ['work', 'notifications', 'people', 'attendance', 'payroll', 'recruitment', 'leave', 'lifecycle', 'performance', 'learning', 'experience', 'assets', 'expenses', 'travel', 'workflows', 'analytics', 'integrations', 'security', 'intelligence']],
        ];
        foreach ($rows as $i => [$code, $name, $description, $price, $modules]) {
            DB::table('plans')->insert(['code' => $code, 'name' => $name, 'description' => $description, 'monthly_price' => $price, 'currency' => 'INR', 'modules' => json_encode($modules), 'sort' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
