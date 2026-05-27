<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * security_events — append-only ledger for prompt-injection, IDOR attempts,
 * auth anomalies, and other security-relevant detector hits.
 *
 * Append-only is enforced at TWO walls:
 *   1. App layer: SecurityEvent model throws on update()/delete()
 *   2. DB layer: PostgreSQL trigger blocks UPDATE/DELETE — the second wall
 *      catches anything that bypasses the model (raw query, console kit).
 *
 * Mirror of audit_log's two-wall pattern (see AuditLogEntry.php). The
 * security ledger is a separate table because:
 *   - Different retention (security: indefinite for forensics; audit: 1 year)
 *   - Different access pattern (security: alerted-on; audit: queried-on)
 *   - Different schema (security has severity + detector_layer + verdict)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();

            // Workspace + brand tenancy — nullable because the detector
            // can fire on system-context calls (boot warmup, evals) that
            // have no tenant attached.
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What kind of event. Open-set: detector services name their own
            // events (e.g. 'prompt_injection.heuristic', 'auth.brute_force',
            // 'idor.cross_tenant_read'). Stored as text to keep the table
            // flexible — if we ever want enum-strict, add a CHECK constraint
            // referencing a config-driven allow-list.
            $table->string('event_type', 80)->index();

            // Severity drives routing: LOW logs only, MEDIUM accumulates in
            // a burst window, HIGH alerts immediately.
            $table->enum('severity', ['low', 'medium', 'high'])->index();

            // Which layer raised the event — used in alert emails so the
            // operator can see whether it was the cheap heuristic or the
            // (paid) LLM grader that escalated.
            $table->string('detector_layer', 40)->nullable();

            // Verdict tier from the detector: 'safe' | 'suspicious' | 'malicious'
            // | 'detector_failure'. The last value records that the detector
            // itself threw — the LLM call still proceeded.
            $table->string('verdict', 40)->nullable();

            // Confidence 0..100. Set by the LLM grader; null for pure-heuristic.
            $table->unsignedTinyInteger('confidence')->nullable();

            // Category from the detector taxonomy: 'instruction_override',
            // 'exfiltration', 'tool_abuse', 'encoding_evasion', 'markdown_smuggling',
            // 'output_leak', 'unknown'. Used by the weekly digest to group.
            $table->string('category', 60)->nullable()->index();

            // The detector's evidence — the offending substring or pattern
            // name. Truncated to 1KB at insert; the full input is in payload.
            $table->text('evidence')->nullable();

            // Full structured detector output + call context (model id,
            // prompt version, agent role, request path, IP). JSON so we can
            // add fields later without a migration.
            $table->jsonb('payload')->nullable();

            // For correlating with ai_costs and Horizon job traces.
            $table->string('correlation_id', 64)->nullable()->index();

            // Did we block the LLM call? Important for incident analysis —
            // a HIGH event with blocked=false means enforcement was off
            // (baseline mode) and the call went through anyway.
            $table->boolean('blocked')->default(false);

            // Did we email the operator? Used by the throttle service to
            // count alerts per workspace per hour.
            $table->boolean('alerted')->default(false);

            $table->timestampTz('occurred_at')->useCurrent()->index();
            $table->timestampsTz();
        });

        // ── Wall #2: DB-level append-only trigger ───────────────────────
        // BEFORE UPDATE / BEFORE DELETE both raise an exception. This is
        // the same pattern as audit_log's trigger. A privileged operator
        // can still drop the trigger if they really need to surgically
        // delete a row (e.g. PII redaction after a data-subject request)
        // — that act is itself audited at the Postgres level via WAL.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION security_events_immutable()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'security_events is append-only (% blocked on row %)',
                    TG_OP, COALESCE(OLD.id::text, 'unknown');
            END;
            $$;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER security_events_block_update
            BEFORE UPDATE ON security_events
            FOR EACH ROW EXECUTE FUNCTION security_events_immutable();
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER security_events_block_delete
            BEFORE DELETE ON security_events
            FOR EACH ROW EXECUTE FUNCTION security_events_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS security_events_block_delete ON security_events');
        DB::statement('DROP TRIGGER IF EXISTS security_events_block_update ON security_events');
        DB::statement('DROP FUNCTION IF EXISTS security_events_immutable()');
        Schema::dropIfExists('security_events');
    }
};
