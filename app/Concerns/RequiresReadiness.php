<?php

namespace App\Concerns;

use App\Models\Brand;
use App\Services\Readiness\SetupReadiness;

/**
 * Mixin for agent jobs / services. Declare the stages you require, then call
 * $this->ensureReady($brand) at the top of your handle().
 *
 * Example:
 *   class StrategistAgent {
 *       use RequiresReadiness;
 *       protected array $requiredStages = ['brand_style', 'platform_connected'];
 *       public function run(Brand $brand) {
 *           $this->ensureReady($brand);
 *           // ... real work
 *       }
 *   }
 */
trait RequiresReadiness
{
    protected array $requiredStages = [];

    protected function ensureReady(Brand $brand): void
    {
        $readiness = app(SetupReadiness::class)->forBrand($brand);
        foreach ($this->requiredStages as $stageId) {
            $readiness->requireStage($stageId);
        }
    }
}
