<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>You've used {{ $pctUsed }}% of this month's posts</title>
</head>
<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif; background:#f8fafc; color:#0f172a; margin:0; padding:32px 0;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="560" style="background:#ffffff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); padding:32px;">
        <tr>
            <td>
                <h1 style="margin:0 0 8px; font-size:20px; line-height:1.3; font-weight:600;">
                    {{ $workspace->name }} is at {{ $pctUsed }}% of this month's posts
                </h1>
                <p style="margin:0 0 16px; color:#475569; font-size:14px; line-height:1.6;">
                    You've published <strong>{{ $postsUsed }}</strong> of your <strong>{{ $postsCap }}</strong> posts on the {{ $planName }} plan this month.
                    You've got {{ max(0, $postsCap - $postsUsed) }} left before things slow down.
                </p>

                <div style="margin:20px 0; padding:16px; background:#fef3c7; border-left:4px solid #f59e0b; border-radius:6px;">
                    <strong style="color:#78350f; font-size:14px;">What happens at 100%?</strong>
                    <p style="margin:6px 0 0; color:#92400e; font-size:13px; line-height:1.5;">
                        Any post past your cap gets queued and auto-publishes on the 1st of next month — you never lose content. But to publish today, you'd need to upgrade.
                    </p>
                </div>

                <p style="margin:24px 0 16px;">
                    <a href="{{ $billingUrl }}"
                       style="display:inline-block; background:#0f172a; color:#ffffff; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:500; font-size:14px;">
                        Review usage &amp; upgrade
                    </a>
                </p>

                <p style="margin:16px 0 0; color:#94a3b8; font-size:12px; line-height:1.5;">
                    You're getting this once per month per workspace, sent the first time your usage crosses 80%.
                    Questions? Reply to this email or write to <a href="mailto:eiaawsolutions@gmail.com" style="color:#475569;">eiaawsolutions@gmail.com</a>.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
