<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\{KnowledgeArticle, KnowledgeFeedback};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class KnowledgeService
{
    public function visible(User $viewer)
    {
        $q = KnowledgeArticle::query();
        if ($viewer->allows('experience.knowledge.manage')) return $q;
        $today = TenantClock::today();
        return $q->where('status', 'published')->where('visibility', 'employee')->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today))
            ->whereNotExists(function ($newer) use ($today) {
                $newer->selectRaw('1')->from('experience_knowledge_articles as newer')
                    ->whereColumn('newer.tenant_id', 'experience_knowledge_articles.tenant_id')
                    ->whereColumn('newer.article_key', 'experience_knowledge_articles.article_key')
                    ->whereColumn('newer.locale', 'experience_knowledge_articles.locale')
                    ->whereColumn('newer.version', '>', 'experience_knowledge_articles.version')
                    ->where('newer.status', 'published')->where('newer.effective_from', '<=', $today);
            });
    }

    public function present(KnowledgeArticle $article, ?User $viewer = null): array
    {
        return ['uuid' => $article->uuid, 'article_key' => $article->article_key, 'version' => $article->version, 'locale' => $article->locale,
            'title' => $article->title, 'summary' => $article->summary, 'body' => $article->body, 'visibility' => $article->visibility,
            'status' => $article->status, 'effective_from' => $article->effective_from?->format('Y-m-d'), 'effective_until' => $article->effective_until?->format('Y-m-d'),
            'feedback_given' => $viewer ? KnowledgeFeedback::where('article_id', $article->id)->where('user_id', $viewer->id)->exists() : false];
    }

    public function feedback(KnowledgeArticle $article, User $viewer, bool $helpful): void
    {
        KnowledgeFeedback::updateOrCreate(['article_id' => $article->id, 'user_id' => $viewer->id], ['uuid' => (string) Str::uuid(), 'helpful' => $helpful]);
    }

    public function feedbackResults(KnowledgeArticle $article): array
    {
        $counts = KnowledgeFeedback::where('article_id', $article->id)->select('helpful', DB::raw('COUNT(*) as total'))->groupBy('helpful')->pluck('total', 'helpful');
        $total = (int) $counts->sum();
        if ($total < 5) return ['suppressed' => true, 'minimum_responses' => 5];
        return ['suppressed' => false, 'response_count' => $total, 'helpful' => (int) ($counts[1] ?? $counts[true] ?? 0) >= 5 ? (int) ($counts[1] ?? $counts[true]) : null,
            'not_helpful' => (int) ($counts[0] ?? $counts[false] ?? 0) >= 5 ? (int) ($counts[0] ?? $counts[false]) : null,
            'helpful_suppressed' => (int) ($counts[1] ?? $counts[true] ?? 0) < 5, 'not_helpful_suppressed' => (int) ($counts[0] ?? $counts[false] ?? 0) < 5];
    }

    public function create(User $actor, array $data): KnowledgeArticle
    {
        $key = Str::slug($data['article_key'] ?? $data['title']);
        if ($key === '') throw ValidationException::withMessages(['article_key' => 'Choose a title that can be used to create an article key.']);
        return DB::transaction(function () use ($actor, $data, $key) {
            $max = KnowledgeArticle::where('article_key', $key)->where('locale', $data['locale'])->lockForUpdate()->max('version') ?? 0;
            $article = KnowledgeArticle::create(['article_key' => $key, 'version' => $max + 1, 'locale' => $data['locale'], 'title' => $data['title'],
                'summary' => $data['summary'] ?? null, 'body' => $data['body'], 'visibility' => $data['visibility'] ?? 'employee', 'status' => 'draft',
                'effective_from' => $data['effective_from'] ?? null, 'effective_until' => $data['effective_until'] ?? null, 'created_by' => $actor->id]);
            Audit::record('knowledge.created', 'experience', 'article:'.$article->uuid, ['article_key' => $key, 'version' => $article->version]);
            return $article;
        });
    }

    public function publish(KnowledgeArticle $article, User $actor): KnowledgeArticle
    {
        if ($article->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only draft articles can be published.']);
        if ($article->effective_until && $article->effective_from && $article->effective_until->lt($article->effective_from)) {
            throw ValidationException::withMessages(['effective_until' => 'The end date must be on or after the effective date.']);
        }
        return DB::transaction(function () use ($article, $actor) {
            if (! $article->effective_from || $article->effective_from->lte(TenantClock::today())) {
                KnowledgeArticle::where('article_key', $article->article_key)->where('locale', $article->locale)->where('status', 'published')
                    ->where('id', '!=', $article->id)->update(['status' => 'retired', 'updated_at' => now()]);
            }
            $article->update(['status' => 'published', 'effective_from' => $article->effective_from ?? TenantClock::today(), 'published_by' => $actor->id]);
            Audit::record('knowledge.published', 'experience', 'article:'.$article->uuid, ['article_key' => $article->article_key, 'version' => $article->version]);
            return $article->refresh();
        });
    }
}
