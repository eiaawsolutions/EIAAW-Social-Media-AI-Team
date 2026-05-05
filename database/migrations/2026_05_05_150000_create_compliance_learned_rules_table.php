<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * compliance_learned_rules — auto-grown memory of platform rejections.
 *
 * Each row is one (platform, rule_kind, fingerprint) tuple representing a
 * distinct failure mode we've observed on a real publish attempt. The
 * recorder upserts on (platform, rule_kind, fingerprint) and bumps
 * occurrences each time the same failure recurs.
 *
 * The `directive` column is the operator-readable "do not / always" line
 * the LearnedRulesProvider injects into Writer/Designer/Compliance prompts.
 *
 * Scope is workspace_id NULL = global (applies to every workspace) or set
 * to a specific workspace for tenant-specific learnings (e.g. a connection's
 * pageId quirk on one Facebook account).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('compliance_learned_rules', function (Blueprint $t) {
            $t->id();

            // null = global rule (applies to every workspace publishing to this platform)
            $t->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();

            $t->string('platform', 30)->index();

            // Machine handle the recorder uses to dedupe. Examples:
            //   'media_required', 'caption_too_long', 'too_many_hashtags',
            //   'malformed_hashtag', 'missing_pageid', 'banned_phrase_match',
            //   'blotato_422', 'blotato_400', 'platform_rejected_unknown'.
            $t->string('rule_kind', 60)->index();

            // Stable fingerprint of the rejection so the same root cause
            // collapses to one row across many failures. md5 of (platform +
            // rule_kind + a normalised reason key extracted by the recorder).
            $t->string('fingerprint', 32);

            // Severity: 'block' = compliance fast-fails on match, 'warn' =
            // surfaced in prompts but doesn't block. Defaults to 'block' on
            // first observation; an operator can demote via the UI.
            $t->string('severity', 10)->default('block')->index();

            // The operator-readable rule. Injected into Writer/Designer
            // prompts as "Do NOT … because <evidence>". Mutable: when the
            // recorder has a better-worded version it overwrites.
            $t->text('directive');

            // Verbatim excerpt of the original rejection (Blotato error,
            // PlatformRules violation reason, etc) — evidence trail.
            $t->text('rejection_excerpt')->nullable();

            // How many times we've seen this exact fingerprint. Drives
            // confidence: 1 occurrence = warning, ≥3 = block, ≥10 = "core".
            $t->unsignedInteger('occurrences')->default(1);

            // First/last observed timestamps — useful for staleness detection.
            // A rule that hasn't been hit in 90d is a candidate for review.
            $t->timestamp('first_seen_at')->useCurrent();
            $t->timestamp('last_seen_at')->useCurrent();

            // The most recent draft + scheduled_post that triggered this
            // rule. Stored loosely (no FK) so deleting a draft doesn't
            // cascade into our learning history.
            $t->unsignedBigInteger('last_draft_id')->nullable();
            $t->unsignedBigInteger('last_scheduled_post_id')->nullable();

            // Operator override: a human can disable a rule entirely (e.g.
            // false-positive) or pin its directive to a custom phrasing.
            $t->boolean('disabled')->default(false)->index();
            $t->text('operator_note')->nullable();

            $t->timestamps();

            // The recorder uses this for atomic upsert.
            $t->unique(['workspace_id', 'platform', 'rule_kind', 'fingerprint'], 'compliance_learned_rules_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_learned_rules');
    }
};
