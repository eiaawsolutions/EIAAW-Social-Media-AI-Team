<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connect your social accounts for {{ $brand->name }}</title>
</head>
<body style="margin: 0; padding: 0; background: #FAF7F2; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0F1A1D;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #FAF7F2; padding: 40px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width: 560px; background: #FFFFFF; border: 1px solid #D9CFBC; border-radius: 16px; overflow: hidden;">
          <tr>
            <td style="padding: 32px 32px 0;">
              <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #11766A;">
                EIAAW SOCIAL MEDIA TEAM &middot; Platform setup
              </div>
              <h1 style="margin: 16px 0 0; font-size: 26px; font-weight: 600; letter-spacing: -0.02em; color: #0F1A1D; line-height: 1.25;">
                Let's connect your social accounts.
              </h1>
              <p style="margin: 16px 0 0; font-size: 15px; line-height: 1.55; color: #2A3438;">
                We've set up a secure space for <strong>{{ $brand->name }}</strong>. The one-time link below lets you connect your Instagram, Facebook, TikTok, LinkedIn, YouTube and Threads accounts &mdash; once they're connected, EIAAW handles the rest: planning, creating and publishing your content on schedule.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center">
                    <a href="{{ $connectUrl }}" style="display: inline-block; background: #11766A; color: #FFFFFF; padding: 16px 32px; border-radius: 999px; font-size: 15px; font-weight: 600; text-decoration: none;">
                      Connect my accounts &rarr;
                    </a>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding-top: 12px;">
                    <span style="font-size: 12px; color: #6B7A7F;">Or paste this link into your browser:</span>
                    <br>
                    <a href="{{ $connectUrl }}" style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 12px; color: #11766A; word-break: break-all;">{{ $connectUrl }}</a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 0;">
              <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 12px;">
                What to do next
              </div>
              <ol style="margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.65; color: #2A3438;">
                <li>Tap <strong>Connect my accounts</strong> above and follow the prompts for each social network you want us to manage. You'll log in to each platform once to authorise the connection &mdash; we never see your passwords.</li>
                <li>Come back to your EIAAW dashboard and click <strong>I've connected my accounts &mdash; check now</strong>. We'll detect your connected accounts automatically.</li>
                <li>That's it &mdash; your content engine is live and we'll start publishing on schedule.</li>
              </ol>
            </td>
          </tr>

          <tr>
            <td style="padding: 24px 32px 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td>
                    <a href="{{ $verifyUrl }}" style="display: inline-block; background: #0F1A1D; color: #FAF7F2; padding: 14px 24px; border-radius: 999px; font-size: 14px; font-weight: 500; text-decoration: none;">
                      Go to EIAAW &mdash; check connection &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding: 24px 32px 0;">
              <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #6B7A7F;">
                This connect link is single-use and expires after a short window. If it's expired by the time you open it, just reply to this email and we'll send a fresh one straight away.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 32px;">
              <hr style="border: none; border-top: 1px dashed #D9CFBC; margin: 0 0 20px;">
              <p style="margin: 0; font-size: 12px; line-height: 1.55; color: #6B7A7F;">
                Questions about connecting your accounts? Reply to this email or write to
                <a href="mailto:{{ $supportEmail }}" style="color: #11766A;">{{ $supportEmail }}</a>
                and we'll help you out within the business day.
              </p>
            </td>
          </tr>
        </table>

        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width: 560px;">
          <tr>
            <td style="padding: 20px 32px 0; text-align: center;">
              <p style="margin: 0; font-size: 11px; line-height: 1.5; color: #A39B88;">
                EIAAW Social Media Team &middot; {{ $workspace->name }}
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
