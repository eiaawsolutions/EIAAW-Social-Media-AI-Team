<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Media generation failed — {{ $brandName }}</title>
</head>
<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif; background:#f8fafc; color:#0f172a; margin:0; padding:32px 0;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="560" style="background:#ffffff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); padding:32px;">
        <tr>
            <td>
                @php
                    $accent = $isLockout ? '#b91c1c' : ($isLowBalance ? '#b45309' : '#b45309');
                    $kicker = $isLockout
                        ? 'Action required — FAL account locked'
                        : ($isLowBalance ? 'Heads up — FAL balance running low' : 'Action required — media generation failed');
                    $heading = $isLowBalance
                        ? 'FAL.AI balance is running low'
                        : ucfirst($mediaKind).' generation failed for '.$brandName;
                @endphp
                <p style="margin:0 0 4px; font-size:12px; font-weight:600; letter-spacing:0.05em; text-transform:uppercase; color:{{ $accent }};">
                    {{ $kicker }}
                </p>
                <h1 style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:600;">
                    {{ $heading }}
                </h1>

                {{-- REASON --}}
                <div style="margin:0 0 16px; padding:16px; background:{{ $isLockout ? '#fee2e2' : '#fef3c7' }}; border-left:4px solid {{ $isLockout ? '#dc2626' : '#f59e0b' }}; border-radius:6px;">
                    <strong style="color:{{ $isLockout ? '#7f1d1d' : '#78350f' }}; font-size:13px; text-transform:uppercase; letter-spacing:0.03em;">Reason</strong>
                    <p style="margin:6px 0 0; color:{{ $isLockout ? '#991b1b' : '#92400e' }}; font-size:14px; line-height:1.55;">
                        {{ $reasonText }}
                    </p>
                </div>

                {{-- ACTION REQUIRED --}}
                <div style="margin:0 0 20px; padding:16px; background:#ecfdf5; border-left:4px solid #10b981; border-radius:6px;">
                    <strong style="color:#065f46; font-size:13px; text-transform:uppercase; letter-spacing:0.03em;">Action required by admin</strong>
                    <p style="margin:6px 0 0; color:#047857; font-size:14px; line-height:1.55;">
                        {{ $actionText }}
                    </p>
                </div>

                {{-- CONTEXT (draft-specific; skipped for the account-wide low-balance warning) --}}
                @unless($isLowBalance)
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 20px; font-size:13px; color:#475569;">
                    <tr>
                        <td style="padding:4px 0; width:120px; color:#94a3b8;">Brand</td>
                        <td style="padding:4px 0;"><strong>{{ $brandName }}</strong></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0; color:#94a3b8;">Draft</td>
                        <td style="padding:4px 0;">#{{ $draftId }} ({{ $platform }})</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0; color:#94a3b8;">Media type</td>
                        <td style="padding:4px 0;">{{ $mediaKind }}</td>
                    </tr>
                    @if($detail !== '')
                    <tr>
                        <td style="padding:4px 0; color:#94a3b8; vertical-align:top;">Detail</td>
                        <td style="padding:4px 0; font-family:ui-monospace,Menlo,monospace; font-size:12px; color:#64748b;">{{ $detail }}</td>
                    </tr>
                    @endif
                </table>
                @endunless

                @if($suppressedCount > 0)
                <p style="margin:0 0 16px; padding:10px 14px; background:#f1f5f9; border-radius:6px; color:#475569; font-size:13px;">
                    <strong>+{{ $suppressedCount }}</strong> more {{ $mediaKind }}-generation failure(s) of the same kind were suppressed since the last alert (throttled to avoid inbox flooding). The blast radius is larger than this single draft.
                </p>
                @endif

                <p style="margin:8px 0 16px;">
                    <a href="{{ $draftsUrl }}"
                       style="display:inline-block; background:#0f172a; color:#ffffff; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:500; font-size:14px;">
                        Open Drafts
                    </a>
                </p>

                <p style="margin:16px 0 0; color:#94a3b8; font-size:12px; line-height:1.5;">
                    This alert fires immediately on a media-generation failure and is throttled to one email per failure-type
                    every {{ (int) config('media.alerts.throttle_minutes', 30) }} minutes. Reply to this email or write to
                    <a href="mailto:eiaawsolutions@gmail.com" style="color:#475569;">eiaawsolutions@gmail.com</a>.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
