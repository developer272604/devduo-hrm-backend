<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Loads per-area boot hooks so parallel workstreams never edit one shared provider.
 *
 * Any class in app/Domain/People/Boot, app/Domain/Attendance/Boot or app/Domain/Leave/Boot (suffix "Boot") may define:
 *   public static function register(\Illuminate\Contracts\Foundation\Application $app): void   // bindings
 *   public static function boot(\Illuminate\Contracts\Foundation\Application $app): void       // registries, runtime config
 */
class ModulesServiceProvider extends ServiceProvider
{
    private const DIRECTORIES = ['Domain/People/Boot', 'Domain/Attendance/Boot', 'Domain/Leave/Boot', 'Domain/Payroll/Boot', 'Domain/Recruitment/Boot', 'Domain/Lifecycle/Boot', 'Domain/Performance/Boot', 'Domain/Operations/Boot'];

    public function register(): void
    {
        foreach ($this->hooks() as $class) {
            if (method_exists($class, 'register')) {
                $class::register($this->app);
            }
        }
    }

    public function boot(): void
    {
        foreach ($this->hooks() as $class) {
            if (method_exists($class, 'boot')) {
                $class::boot($this->app);
            }
        }
    }

    /** @return array<int,class-string> */
    private function hooks(): array
    {
        $classes = [];
        foreach (self::DIRECTORIES as $directory) {
            foreach (glob(app_path($directory.'/*Boot.php')) ?: [] as $file) {
                $classes[] = 'App\\'.str_replace('/', '\\', $directory).'\\'.basename($file, '.php');
            }
        }
        sort($classes);

        return $classes;
    }
}
