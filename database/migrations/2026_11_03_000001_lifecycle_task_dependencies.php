<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        foreach (['lifecycle_template_tasks', 'lifecycle_tasks'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->json('depends_on')->nullable();
                $t->text('acknowledgment_text')->nullable();
                $t->unsignedSmallInteger('remind_before_days')->default(2);
                $t->unsignedSmallInteger('escalate_after_days')->default(1);
            });
        }
        Schema::table('lifecycle_tasks', fn (Blueprint $t) => $t->dateTime('acknowledged_at')->nullable());
    }
    public function down(): void {
        Schema::table('lifecycle_tasks', fn (Blueprint $t) => $t->dropColumn('acknowledged_at'));
        foreach (['lifecycle_template_tasks', 'lifecycle_tasks'] as $name) Schema::table($name, fn (Blueprint $t) => $t->dropColumn(['depends_on', 'acknowledgment_text', 'remind_before_days', 'escalate_after_days']));
    }
};
