<?php

namespace Tests\Unit;

use App\Agents\BaseAgent;
use Tests\TestCase;

/**
 * Regression for the prod incident: RAG corpus slices used byte-based substr()
 * (substr($content, 0, 800)), which splits a UTF-8 multibyte character when the
 * byte boundary lands mid-sequence. The malformed bytes then reached the
 * Anthropic SDK's json_encode, which threw "Malformed UTF-8 characters" and
 * failed every Researcher/Writer call for any brand whose top-K corpus pulled
 * one of those rows. BaseAgent::safeExcerpt truncates by CHARACTER (mb_substr),
 * so the result is always valid UTF-8. DB-free.
 */
class SafeExcerptUtf8Test extends TestCase
{
    public function test_truncating_mid_multibyte_char_stays_valid_utf8(): void
    {
        // "…" (U+2026, 3 bytes: E2 80 A6) straddling the cut point. A byte-based
        // substr(…, 0, N) that lands inside those 3 bytes yields invalid UTF-8.
        $s = str_repeat('a', 799) . '…' . 'tail';

        // Prove the OLD behaviour was broken at byte 800 (mid-ellipsis).
        $this->assertFalse(
            mb_check_encoding(substr($s, 0, 800), 'UTF-8'),
            'precondition: byte-substr at 800 splits the multibyte char'
        );

        // The fix: safeExcerpt never returns invalid UTF-8.
        $out = BaseAgent::safeExcerpt($s, 800);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'), 'safeExcerpt must return valid UTF-8');
    }

    public function test_excerpt_respects_character_limit(): void
    {
        $s = str_repeat('é', 1000); // 1000 chars, 2000 bytes
        $out = BaseAgent::safeExcerpt($s, 800);

        $this->assertSame(800, mb_strlen($out));
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    public function test_short_string_passes_through_unchanged(): void
    {
        $this->assertSame('hello', BaseAgent::safeExcerpt('hello', 800));
    }

    public function test_handles_emoji_boundary(): void
    {
        // 4-byte emoji on the boundary.
        $s = str_repeat('x', 798) . '🚀' . 'yz';
        $this->assertTrue(mb_check_encoding(BaseAgent::safeExcerpt($s, 800), 'UTF-8'));
        $this->assertTrue(mb_check_encoding(BaseAgent::safeExcerpt($s, 799), 'UTF-8'));
    }
}
