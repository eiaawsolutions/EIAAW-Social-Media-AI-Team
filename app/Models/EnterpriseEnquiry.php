<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A lead from the dedicated /enterprise "Talk to us" page. Richer than
 * SupportEnquiry — carries the sales-scoping fields (company size, brand count,
 * monthly video volume, budget band) the team uses to shape a bespoke plan.
 *
 * Lead Generation Contract (global): only visitor-submitted data is stored; no
 * fabricated or enriched contact fields. See the migration for the column intent.
 */
class EnterpriseEnquiry extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'company', 'website',
        'company_size', 'brands_needed', 'videos_per_month', 'budget_band',
        'message',
        'ip_hash', 'user_agent', 'referer',
        'status', 'handled_at',
        // Provisioning + invoice (invoice-based Enterprise deals).
        'agreed_brands', 'agreed_image_posts', 'agreed_video_posts', 'agreed_price_myr',
        'stripe_invoice_id', 'stripe_invoice_url', 'invoice_status', 'invoice_paid_at',
        'provisioned_workspace_id',
    ];

    protected function casts(): array
    {
        return [
            'brands_needed' => 'integer',
            'videos_per_month' => 'integer',
            'handled_at' => 'datetime',
            'agreed_brands' => 'integer',
            'agreed_image_posts' => 'integer',
            'agreed_video_posts' => 'integer',
            'agreed_price_myr' => 'integer',
            'invoice_paid_at' => 'datetime',
            'provisioned_workspace_id' => 'integer',
        ];
    }

    public function provisionedWorkspace(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'provisioned_workspace_id');
    }
}
