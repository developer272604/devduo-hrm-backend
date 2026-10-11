<?php

namespace App\Domain\Insights;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/** Registry of InsightProviders. for() merges their insights, enforces "every insight has an action" and sorts by severity then count. */
final class ActionCenter
{
    private const ORDER = ['critical' => 0, 'action_required' => 1, 'warning' => 2, 'info' => 3];

    /** @var array<int,InsightProvider|string> */
    private array $providers = [];

    public function register(InsightProvider|string $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return array<string,InsightProvider> keyed by provider key (later registrations replace earlier ones). */
    public function providers(): array
    {
        $out = [];
        foreach ($this->providers as $i => $p) {
            $p = is_string($p) ? ($this->providers[$i] = app($p)) : $p;
            $out[$p->key()] = $p;
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function for(User $viewer, ?string $domain = null): array
    {
        $out = [];
        foreach ($this->providers() as $key => $provider) {
            try {
                foreach ($provider->insights($viewer) as $insight) {
                    if (! $this->valid($insight) || ($domain !== null && $insight['domain'] !== $domain)) {
                        continue;
                    }
                    $out[] = $insight;
                }
            } catch (\Throwable $e) {
                Log::warning('Insight provider failed', ['provider' => $key, 'error' => $e->getMessage()]);
            }
        }
        usort($out, fn ($a, $b) => [self::ORDER[$a['severity']], -$a['count']] <=> [self::ORDER[$b['severity']], -$b['count']]);

        return $out;
    }

    private function valid(mixed $i): bool
    {
        if (! is_array($i) || ! isset($i['key'], $i['domain'], $i['severity'], $i['title'], $i['count']) || ! isset(self::ORDER[$i['severity']]) || (int) $i['count'] < 1) {
            return false;
        }
        $actions = $i['actions'] ?? [];
        if (! is_array($actions) || ! $actions) {
            return false;
        }
        foreach ($actions as $a) {
            $kind = $a['kind'] ?? null;
            if (! isset($a['label']) || ! in_array($kind, ['navigate', 'api'], true) || ($kind === 'navigate' && empty($a['href'])) || ($kind === 'api' && (empty($a['endpoint']) || empty($a['method'])))) {
                return false;
            }
        }

        return true;
    }
}
