<?php

namespace App\Filament\Agency\Pages;

use App\Models\Brand;
use App\Models\ScheduledPost;
use App\Models\Workspace;
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
    protected static ?int $navigationSort = 11;
    protected static ?string $slug = 'live-feed';
    protected string $view = 'filament.agency.pages.live-feed';

    /** Livewire-safe scalar state. */
    public ?string $platformFilter = null; // null = all platforms

    public function mount(): void
    {
        $this->platformFilter = request()->string('platform')->toString() ?: null;
    }

    public function setPlatform(?string $platform): void
    {
        $this->platformFilter = $platform ?: null;
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

        return $q->limit(120)->get();
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
     * Best-available click target for a published post tile. Prefers the
     * captured `platform_post_url` (the exact permalink Blotato returned).
     * Falls back to the connected account's profile URL when Blotato
     * confirmed `published` but didn't return a permalink — which is
     * better UX than a dead anchor and still routes the operator to the
     * correct account on the right platform.
     */
    public function clickUrl(ScheduledPost $post): ?string
    {
        if (! empty($post->platform_post_url)) {
            return $post->platform_post_url;
        }
        $platform = $post->draft?->platform;
        $handle = $post->platformConnection?->display_handle;
        if (! $platform || ! $handle) return null;

        $clean = strtolower(preg_replace('/[^a-zA-Z0-9._-]+/', '', $handle) ?? '');
        if ($clean === '') return null;

        return match ($platform) {
            'instagram' => "https://www.instagram.com/{$clean}/",
            'tiktok'    => "https://www.tiktok.com/@{$clean}",
            'threads'   => "https://www.threads.com/@{$clean}",
            'youtube'   => "https://www.youtube.com/@{$clean}",
            'x', 'twitter' => "https://x.com/{$clean}",
            'facebook'  => "https://www.facebook.com/{$clean}",
            'linkedin'  => "https://www.linkedin.com/in/{$clean}",
            'pinterest' => "https://www.pinterest.com/{$clean}/",
            default     => null,
        };
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
        $tail = $publishing > 0
            ? "{$publishing} confirming with platform · published posts appear once the network confirms"
            : 'only posts confirmed live on the platform appear here';
        return $ws->name . ' · ' . $this->brandTimezone() . ' · ' . $tail;
    }
}
