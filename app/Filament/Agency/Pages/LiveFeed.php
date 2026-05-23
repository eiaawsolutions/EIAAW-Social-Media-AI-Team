<?php

namespace App\Filament\Agency\Pages;

use App\Models\Brand;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use App\Services\Publishing\PostVerificationRules;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Live Feed — the receipt page. Grid of every post that's actually
 * published on a real platform, with image/video preview, caption,
 * platform badge, published_at in brand timezone, and a click-through
 * to the live platform URL.
 *
 * What this page is NOT: queued/failed/cancelled posts. For the full
 * pipeline view (queued, submitting, retry candidates) use
 * /agency/scheduled-posts.
 */
class LiveFeed extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?string $navigationLabel = 'Live feed';
    protected static ?string $title = 'Live feed';
    protected static ?int $navigationSort = 9;
    protected static ?string $slug = 'live-feed';
    protected string $view = 'filament.agency.pages.live-feed';

    /** Livewire-safe scalar state. */
    public ?string $platformFilter = null; // null = all platforms
    public ?string $statusFilter = null;   // null = published+publishing+unverified, else 'published' | 'publishing' | 'unverified'
    public ?string $dateFrom = null;       // YYYY-MM-DD, brand-tz day inclusive
    public ?string $dateUntil = null;      // YYYY-MM-DD, brand-tz day inclusive
    public ?string $search = null;         // caption substring (drafts.body)

    public function mount(): void
    {
        $this->platformFilter = request()->string('platform')->toString() ?: null;
    }

    public function setPlatform(?string $platform): void
    {
        $this->platformFilter = $platform ?: null;
    }

    public function resetFilters(): void
    {
        $this->statusFilter = null;
        $this->dateFrom = null;
        $this->dateUntil = null;
        $this->search = null;
    }

    /**
     * The values are wire-modeled from a Livewire-aware blade form, so they
     * arrive as strings (or empty strings). Normalise empty-string → null so
     * the query builder skips them.
     */
    public function updated(string $name): void
    {
        if (in_array($name, ['statusFilter', 'dateFrom', 'dateUntil', 'search'], true)) {
            $val = $this->{$name};
            if (is_string($val) && trim($val) === '') {
                $this->{$name} = null;
            }
        }
    }

    public function workspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) return null;
        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }

    public function brandTimezone(): string
    {
        $ws = $this->workspace();
        if (! $ws) return 'UTC';
        $brand = Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();
        return $brand?->timezone ?: 'UTC';
    }

    /** @return \Illuminate\Support\Collection<int, ScheduledPost> */
    public function posts()
    {
        $ws = $this->workspace();
        if (! $ws) return collect();

        $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');

        // Show published rows AND submitted rows (the "publishing —
        // confirming with platform" gap). Submitted-without-blotato_post_id
        // is excluded since those haven't actually been submitted to the
        // network yet (transient `submitting` state).
        $q = ScheduledPost::with(['draft.calendarEntry', 'brand', 'platformConnection'])
            ->whereIn('brand_id', $brandIds)
            ->where(function ($q) {
                $q->where('status', 'published')
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'submitted')
                         ->whereNotNull('blotato_post_id');
                  });
            })
            ->orderByRaw("CASE WHEN status='published' THEN 0 ELSE 1 END")
            ->orderByDesc('published_at')
            ->orderByDesc('submitted_at');

        if ($this->platformFilter) {
            $q->whereHas('draft', fn ($d) => $d->where('platform', $this->platformFilter));
        }

        // Status filter: 'published' = confirmed live; 'publishing' = submitted,
        // awaiting platform confirmation. 'unverified' filtered post-fetch via
        // PostVerificationRules since it depends on the platform_post_url
        // shape, not a column. Null = all three buckets.
        if ($this->statusFilter === 'published') {
            $q->where('status', 'published');
        } elseif ($this->statusFilter === 'publishing') {
            $q->where('status', 'submitted')->whereNotNull('blotato_post_id');
        }

        // Date range against published_at (falls back to submitted_at for
        // publishing-only rows). Brand-tz day boundaries.
        $tz = $this->brandTimezone();
        if ($this->dateFrom) {
            $from = Carbon::parse($this->dateFrom, $tz)->startOfDay()->setTimezone('UTC');
            $q->where(function ($qq) use ($from) {
                $qq->where('published_at', '>=', $from)
                   ->orWhere(function ($qq2) use ($from) {
                       $qq2->whereNull('published_at')
                           ->where('submitted_at', '>=', $from);
                   });
            });
        }
        if ($this->dateUntil) {
            $until = Carbon::parse($this->dateUntil, $tz)->endOfDay()->setTimezone('UTC');
            $q->where(function ($qq) use ($until) {
                $qq->where('published_at', '<=', $until)
                   ->orWhere(function ($qq2) use ($until) {
                       $qq2->whereNull('published_at')
                           ->where('submitted_at', '<=', $until);
                   });
            });
        }

        if ($this->search) {
            $needle = trim($this->search);
            $q->whereHas('draft', fn ($d) => $d->where('body', 'ILIKE', '%' . $needle . '%'));
        }

        $rows = $q->limit(120)->get();

        // Unverified filter: rows in `published` status that don't pass the
        // truthfulness rules (no real platform_post_url permalink yet).
        if ($this->statusFilter === 'unverified') {
            $rows = $rows->filter(function ($p) {
                if ($p->status !== 'published') return false;
                $platform = (string) ($p->draft?->platform ?? '');
                return ! PostVerificationRules::isRealPostUrl($platform, $p->platform_post_url);
            })->values();
        }

        return $rows;
    }

    /** @return array<string, int>  platform => count (published only) */
    public function platformCounts(): array
    {
        $ws = $this->workspace();
        if (! $ws) return [];
        $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');

        return ScheduledPost::query()
            ->join('drafts', 'drafts.id', '=', 'scheduled_posts.draft_id')
            ->whereIn('scheduled_posts.brand_id', $brandIds)
            ->where('scheduled_posts.status', 'published')
            ->selectRaw('drafts.platform, COUNT(*) as c')
            ->groupBy('drafts.platform')
            ->orderByDesc('c')
            ->pluck('c', 'platform')
            ->all();
    }

    public function totalLive(): int
    {
        return array_sum($this->platformCounts());
    }

    public function totalPublishing(): int
    {
        $ws = $this->workspace();
        if (! $ws) return 0;
        $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');
        return ScheduledPost::whereIn('brand_id', $brandIds)
            ->where('status', 'submitted')
            ->whereNotNull('blotato_post_id')
            ->count();
    }

    /**
     * Count of `published` rows that don't pass PostVerificationRules — i.e.
     * Blotato said published but never returned a real permalink. These render
     * as "unverified" tiles in the feed; operator can run
     * `php artisan posts:reconcile-published --apply` to clean them up.
     */
    public function totalUnverified(): int
    {
        $ws = $this->workspace();
        if (! $ws) return 0;
        $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');
        $rows = ScheduledPost::with('draft')
            ->whereIn('brand_id', $brandIds)
            ->where('status', 'published')
            ->get(['id', 'draft_id', 'platform_post_url']);
        return $rows->filter(function ($p) {
            $platform = (string) ($p->draft?->platform ?? '');
            return ! PostVerificationRules::isRealPostUrl($platform, $p->platform_post_url);
        })->count();
    }

    /**
     * Click target for a tile. ONLY returns a URL that PostVerificationRules
     * recognises as a real post permalink (e.g. instagram.com/p/<id>/, NOT
     * instagram.com/<handle>). Profile-root URLs and empty URLs return null
     * so the tile renders unclickable — the truthfulness contract: the live
     * feed MUST NOT pretend a post exists at a URL when it doesn't.
     *
     * Reason: Blotato has been observed to return state=published before
     * the platform actually has the post. A profile-fallback link would
     * silently mask that gap and lead the operator to scroll a feed
     * looking for a post that isn't there. Better to show "verifying with
     * platform" and disable the click than to falsely promise a live URL.
     */
    public function clickUrl(ScheduledPost $post): ?string
    {
        $platform = (string) ($post->draft?->platform ?? '');
        $url = $post->platform_post_url;
        return PostVerificationRules::isRealPostUrl($platform, $url) ? $url : null;
    }

    public function getHeading(): string|Htmlable
    {
        $total = $this->totalLive();
        return $total === 0
            ? 'Live feed'
            : "Live feed — {$total} post" . ($total === 1 ? '' : 's') . ' published';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $ws = $this->workspace();
        if (! $ws) return null;
        $publishing = $this->totalPublishing();
        $unverified = $this->totalUnverified();

        $bits = [];
        if ($publishing > 0) {
            $bits[] = "{$publishing} confirming with platform";
        }
        if ($unverified > 0) {
            $bits[] = "{$unverified} unverified (run posts:reconcile-published)";
        }
        $tail = $bits
            ? implode(' · ', $bits)
            : 'only posts confirmed live on the platform appear here';

        return $ws->name . ' · ' . $this->brandTimezone() . ' · ' . $tail;
    }
}
