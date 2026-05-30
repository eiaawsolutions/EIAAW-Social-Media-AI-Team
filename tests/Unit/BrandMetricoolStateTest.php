<?php

namespace Tests\Unit;

use App\Models\Brand;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Brand::metricoolSetupState() — the onboarding state machine driving the
 * MetricoolSetup wizard. Pure in-memory model logic; DB-free.
 */
class BrandMetricoolStateTest extends TestCase
{
    private function brand(array $attrs): Brand
    {
        $b = new Brand();
        foreach ($attrs as $k => $v) {
            $b->{$k} = $v;
        }
        return $b;
    }

    public function test_not_mapped_when_no_blog_id(): void
    {
        $b = $this->brand(['metricool_blog_id' => null]);
        $this->assertSame('not_mapped', $b->metricoolSetupState());
        $this->assertFalse($b->hasMetricoolConnected());
    }

    public function test_mapped_when_blog_id_but_no_link_or_connection(): void
    {
        $b = $this->brand([
            'metricool_blog_id' => '6322515',
            'metricool_connect_link_sent_at' => null,
            'metricool_connected_at' => null,
        ]);
        $this->assertSame('mapped', $b->metricoolSetupState());
    }

    public function test_link_sent_when_link_stamped_but_not_connected(): void
    {
        $b = $this->brand([
            'metricool_blog_id' => '6322515',
            'metricool_connect_link_sent_at' => Carbon::parse('2026-05-30 10:00:00'),
            'metricool_connected_at' => null,
        ]);
        $this->assertSame('link_sent', $b->metricoolSetupState());
    }

    public function test_connected_wins_over_everything_once_connected_at_set(): void
    {
        $b = $this->brand([
            'metricool_blog_id' => '6322515',
            'metricool_connect_link_sent_at' => Carbon::parse('2026-05-30 10:00:00'),
            'metricool_connected_at' => Carbon::parse('2026-05-30 11:00:00'),
        ]);
        $this->assertSame('connected', $b->metricoolSetupState());
        $this->assertTrue($b->hasMetricoolConnected());
    }

    public function test_connected_requires_blog_id_too(): void
    {
        // connected_at set but no blogId (shouldn't happen, but guard it) →
        // not treated as connected.
        $b = $this->brand([
            'metricool_blog_id' => null,
            'metricool_connected_at' => Carbon::parse('2026-05-30 11:00:00'),
        ]);
        $this->assertFalse($b->hasMetricoolConnected());
        $this->assertSame('not_mapped', $b->metricoolSetupState());
    }
}
