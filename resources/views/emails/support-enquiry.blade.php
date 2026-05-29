<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>New SMT enquiry from {{ $enquiry->name }}</title>
</head>
<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif; background:#f8fafc; color:#0f172a; margin:0; padding:32px 0;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="560" style="background:#ffffff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); padding:32px;">
        <tr>
            <td>
                <p style="margin:0 0 4px; font-family:ui-monospace,monospace; font-size:11px; letter-spacing:0.12em; text-transform:uppercase; color:#11766A;">New website enquiry</p>
                <h1 style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:600;">
                    {{ $enquiry->name }} wants to talk
                </h1>

                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="font-size:14px; color:#0f172a;">
                    <tr>
                        <td style="padding:6px 0; color:#64748b; width:110px; vertical-align:top;">Email</td>
                        <td style="padding:6px 0;"><a href="mailto:{{ $enquiry->email }}" style="color:#11766A;">{{ $enquiry->email }}</a></td>
                    </tr>
                    @if ($enquiry->phone !== '')
                    <tr>
                        <td style="padding:6px 0; color:#64748b; vertical-align:top;">Phone</td>
                        <td style="padding:6px 0;">{{ $enquiry->phone }}</td>
                    </tr>
                    @endif
                    @if ($enquiry->company !== '')
                    <tr>
                        <td style="padding:6px 0; color:#64748b; vertical-align:top;">Company</td>
                        <td style="padding:6px 0;">{{ $enquiry->company }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding:6px 0; color:#64748b; vertical-align:top;">Source</td>
                        <td style="padding:6px 0;">{{ ['landing' => 'Landing page', 'client' => 'Client panel', 'hq' => 'HQ panel'][$enquiry->surface] ?? $enquiry->surface }}</td>
                    </tr>
                </table>

                <div style="margin:20px 0; padding:16px; background:#f1f5f9; border-radius:8px;">
                    <strong style="display:block; margin-bottom:8px; font-size:13px; color:#334155;">Message</strong>
                    <p style="margin:0; font-size:14px; line-height:1.6; color:#0f172a; white-space:pre-wrap;">{{ $enquiry->message }}</p>
                </div>

                <a href="mailto:{{ $enquiry->email }}?subject=Re: your EIAAW Social Media Team enquiry"
                   style="display:inline-block; background:#11766A; color:#ffffff; text-decoration:none; font-size:14px; font-weight:600; padding:12px 20px; border-radius:8px;">
                    Reply to {{ $enquiry->name }}
                </a>

                <p style="margin:24px 0 0; font-size:12px; color:#94a3b8; line-height:1.5;">
                    Enquiry #{{ $enquiry->id }} &middot; received {{ $enquiry->created_at->format('d M Y, H:i') }} &middot; also saved in HQ → Enquiries.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
