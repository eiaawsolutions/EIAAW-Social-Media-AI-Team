<x-filament-panels::page>
    @push('styles')
        <style>
            .cm-wrap { display: grid; gap: 20px; }
            .cm-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
            .cm-card {
                background: var(--gray-50, #f9fafb);
                border: 1px solid var(--gray-200, #e5e7eb);
                border-radius: 12px;
                padding: 18px 20px;
            }
            .dark .cm-card { background: rgba(255,255,255,.02); border-color: rgba(255,255,255,.08); }
            .cm-card h3 {
                font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
                color: var(--gray-500, #6b7280); margin: 0 0 12px;
            }
            .cm-table { width: 100%; border-collapse: collapse; font-size: 14px; }
            .cm-table th, .cm-table td { text-align: left; padding: 9px 10px; }
            .cm-table th {
                font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
                color: var(--gray-500, #6b7280); border-bottom: 1px solid var(--gray-200, #e5e7eb);
            }
            .dark .cm-table th { border-color: rgba(255,255,255,.08); }
            .cm-table td { border-bottom: 1px dashed var(--gray-200, #e5e7eb); }
            .dark .cm-table td { border-color: rgba(255,255,255,.06); }
            .cm-table tr:last-child td { border-bottom: 0; }
            .cm-num { text-align: right; font-variant-numeric: tabular-nums; font-family: ui-monospace, monospace; white-space: nowrap; }
            .cm-total td { font-weight: 800; border-top: 2px solid var(--gray-300, #d1d5db); border-bottom: 0 !important; }
            .dark .cm-total td { border-color: rgba(255,255,255,.18); }
            .cm-tag {
                display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: .04em;
                padding: 2px 7px; border-radius: 999px; vertical-align: middle; margin-left: 8px;
            }
            .cm-tag-measured { background: rgba(17,118,106,.12); color: #11766A; }
            .dark .cm-tag-measured { background: rgba(17,118,106,.25); color: #5eead4; }
            .cm-tag-operator { background: rgba(245,158,11,.14); color: #b45309; }
            .dark .cm-tag-operator { background: rgba(245,158,11,.2); color: #fcd34d; }
            .cm-warn {
                background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.35);
                border-radius: 10px; padding: 12px 14px; font-size: 13px; color: #92400e;
            }
            .dark .cm-warn { color: #fde68a; }
            .cm-warn ul { margin: 6px 0 0; padding-left: 18px; }
            .cm-grid-2 { display: grid; gap: 20px; grid-template-columns: 1fr; }
            @media (min-width: 1024px) { .cm-grid-2 { grid-template-columns: 1fr 1fr; } }
            .cm-muted { color: var(--gray-500, #6b7280); }
            .cm-pl-row td { font-weight: 600; }
            .cm-profit-pos { color: #11766A; }
            .dark .cm-profit-pos { color: #5eead4; }
            .cm-profit-neg { color: #dc2626; }
            .dark .cm-profit-neg { color: #fca5a5; }
        </style>
    @endpush

    @php($s = $this->snapshot())

    <div class="cm-wrap" wire:poll.30s>

        {{-- Month picker --}}
        <div class="cm-toolbar">
            <label for="cm-month" class="text-sm font-medium cm-muted">Month</label>
            <select
                id="cm-month"
                wire:model.live="monthAnchor"
                class="fi-input block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm"
            >
                @foreach ($this->monthOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($s['period']['is_current'])
                <span class="text-xs cm-muted">
                    Day {{ $s['period']['day_of_month'] }} of {{ $s['period']['days_in_month'] }} ·
                    figures are month-to-date · FX 1 USD = RM {{ number_format($s['fx'], 2) }}
                </span>
            @else
                <span class="text-xs cm-muted">Closed month · FX 1 USD = RM {{ number_format($s['fx'], 2) }}</span>
            @endif
        </div>

        {{-- Honesty warnings --}}
        @if (count($s['warnings']) > 0)
            <div class="cm-warn">
                <strong>Heads up — the profit line may be overstated:</strong>
                <ul>
                    @foreach ($s['warnings'] as $w)
                        <li>{{ $w }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="cm-grid-2">
            {{-- Revenue breakdown --}}
            <div class="cm-card">
                <h3>Revenue — live paying base <span class="cm-tag cm-tag-measured">measured</span></h3>
                <table class="cm-table">
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th class="cm-num">Live</th>
                            <th class="cm-num">RM / mo</th>
                            <th class="cm-num">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($s['revenue']['by_plan'] as $plan => $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="cm-num">{{ $row['count'] }}</td>
                                <td class="cm-num">{{ number_format($row['unit_myr'], 0) }}</td>
                                <td class="cm-num">{{ number_format($row['subtotal_myr'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="cm-muted">No paying workspaces yet.</td></tr>
                        @endforelse
                        <tr class="cm-total">
                            <td colspan="3">Monthly recurring revenue</td>
                            <td class="cm-num">RM {{ number_format($s['revenue']['total_myr'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
                @if ($s['signups']['internal'] > 0)
                    <p class="text-xs cm-muted" style="margin-top:10px;">
                        {{ $s['signups']['internal'] }} internal EIAAW workspace(s) excluded (no revenue).
                    </p>
                @endif
            </div>

            {{-- Cost breakdown --}}
            <div class="cm-card">
                <h3>Running cost</h3>
                <table class="cm-table">
                    <thead>
                        <tr>
                            <th>Cost line</th>
                            <th></th>
                            <th class="cm-num">RM</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- AI spend by provider (measured) --}}
                        @foreach ($s['costs']['ai_by_provider'] as $provider => $amount)
                            <tr>
                                <td>AI — {{ ucfirst($provider) }}</td>
                                <td><span class="cm-tag cm-tag-measured">measured</span></td>
                                <td class="cm-num">{{ number_format($amount, 2) }}</td>
                            </tr>
                        @endforeach
                        @if (count($s['costs']['ai_by_provider']) === 0)
                            <tr>
                                <td>AI spend</td>
                                <td><span class="cm-tag cm-tag-measured">measured</span></td>
                                <td class="cm-num">0.00</td>
                            </tr>
                        @endif

                        {{-- Blotato (operator rate × live count) --}}
                        <tr>
                            <td>
                                Blotato seats
                                <span class="cm-muted">({{ $s['signups']['blotato_provisioned'] }} × ${{ number_format((float) config('costs.per_workspace.blotato.amount_usd'), 0) }})</span>
                            </td>
                            <td><span class="cm-tag cm-tag-operator">operator rate</span></td>
                            <td class="cm-num">{{ number_format($s['costs']['blotato_myr'], 2) }}</td>
                        </tr>

                        {{-- Fixed infra lines (operator-set) --}}
                        @foreach ($s['costs']['fixed_lines'] as $line)
                            <tr>
                                <td>
                                    {{ $line['label'] }}
                                    @if ($line['is_zero'])
                                        <span class="cm-muted">· not set</span>
                                    @endif
                                </td>
                                <td><span class="cm-tag cm-tag-operator">operator-set</span></td>
                                <td class="cm-num">{{ number_format($line['amount_myr'], 2) }}</td>
                            </tr>
                        @endforeach

                        <tr class="cm-total">
                            <td colspan="2">Total running cost</td>
                            <td class="cm-num">RM {{ number_format($s['costs']['total_myr'], 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Profit & Loss summary --}}
        <div class="cm-card">
            <h3>Profit &amp; loss — {{ $s['period']['label'] }}</h3>
            <table class="cm-table">
                <tbody>
                    <tr class="cm-pl-row">
                        <td>Revenue (live MRR)</td>
                        <td class="cm-num">RM {{ number_format($s['revenue']['total_myr'], 2) }}</td>
                    </tr>
                    <tr class="cm-pl-row">
                        <td>Less: AI spend (measured)</td>
                        <td class="cm-num">− {{ number_format($s['costs']['ai_myr'], 2) }}</td>
                    </tr>
                    <tr class="cm-pl-row">
                        <td>Less: Blotato seats</td>
                        <td class="cm-num">− {{ number_format($s['costs']['blotato_myr'], 2) }}</td>
                    </tr>
                    <tr class="cm-pl-row">
                        <td>Less: fixed infra</td>
                        <td class="cm-num">− {{ number_format($s['costs']['fixed_myr'], 2) }}</td>
                    </tr>
                    <tr class="cm-total">
                        <td>
                            Net profit
                            @if ($s['profit']['margin_pct'] !== null)
                                <span class="cm-muted">({{ $s['profit']['margin_pct'] }}% margin)</span>
                            @endif
                        </td>
                        <td class="cm-num {{ $s['profit']['net_myr'] >= 0 ? 'cm-profit-pos' : 'cm-profit-neg' }}">
                            RM {{ number_format($s['profit']['net_myr'], 2) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            @if ($s['projection'])
                <p class="text-xs cm-muted" style="margin-top:14px;">
                    <strong>Month-end projection</strong> (AI spend run-rated from
                    {{ $s['period']['day_of_month'] }}/{{ $s['period']['days_in_month'] }} days; recurring lines held):
                    revenue RM {{ number_format($s['projection']['revenue_myr'], 2) }} ·
                    projected AI RM {{ number_format($s['projection']['ai_cost_myr'], 2) }} ·
                    projected profit
                    <span class="{{ $s['projection']['profit_myr'] >= 0 ? 'cm-profit-pos' : 'cm-profit-neg' }}">
                        RM {{ number_format($s['projection']['profit_myr'], 2) }}
                    </span>.
                </p>
            @endif
        </div>

        <p class="text-xs cm-muted">
            <span class="cm-tag cm-tag-measured">measured</span> figures come from real ledger / live tables and move with usage and signups.
            <span class="cm-tag cm-tag-operator">operator-set</span> figures are entered in <code>config/costs.php</code> — keep them current so profit stays truthful.
        </p>
    </div>
</x-filament-panels::page>
