<?php

namespace Tests\Unit;

use App\Services\Content\RewordAssistant;
use Tests\TestCase;

/**
 * Locks the truncation behaviour the reword service applies after parsing the
 * model output, so a model that overruns the cap can never persist an
 * unpublishable caption (or an over-long asset description).
 *
 * DB-FREE by design — pure static helpers, no LLM call, no DB.
 */
class RewordCharCapTest extends TestCase
{
    public function test_clamp_to_cap_truncates_over_cap(): void
    {
        $text = str_repeat('a', 300);
        $this->assertSame(280, mb_strlen(RewordAssistant::clampToCap($text, 280)));
    }

    public function test_clamp_to_cap_passes_under_cap(): void
    {
        $this->assertSame('short caption', RewordAssistant::clampToCap('short caption', 280));
    }

    public function test_clamp_to_cap_zero_is_passthrough(): void
    {
        $text = str_repeat('z', 5000);
        $this->assertSame($text, RewordAssistant::clampToCap($text, 0));
    }

    public function test_clamp_to_cap_is_multibyte_safe(): void
    {
        // 10 multibyte chars; capping to 5 must keep 5 characters, not 5 bytes.
        $text = str_repeat('é', 10);
        $this->assertSame(5, mb_strlen(RewordAssistant::clampToCap($text, 5)));
    }

    public function test_clamp_to_words_keeps_first_n_words(): void
    {
        $text = 'one two three four five six seven eight nine ten eleven twelve';
        $out = RewordAssistant::clampToWords($text, 5);
        $this->assertSame('one two three four five', $out);
    }

    public function test_clamp_to_words_collapses_whitespace_and_passes_short(): void
    {
        $this->assertSame('a warm cafe interior', RewordAssistant::clampToWords("  a   warm\ncafe   interior  ", 20));
    }

    public function test_clamp_to_words_zero_is_passthrough(): void
    {
        $this->assertSame('keep all of these words', RewordAssistant::clampToWords('keep all of these words', 0));
    }
}
