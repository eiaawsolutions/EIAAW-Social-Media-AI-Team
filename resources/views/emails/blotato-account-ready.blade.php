<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your Blotato publishing account is ready</title>
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
              <h1 style="margin: 16px 0 0; font-size: 26px; font-weight: 600; letter-spacing: -0.02em; color: #0F1A1D; line-height: 1.2;">
                Your Blotato publishing account is ready.
              </h1>
              <p style="margin: 16px 0 0; font-size: 15px; line-height: 1.55; color: #2A3438;">
                We've provisioned a dedicated Blotato account for <strong>{{ $workspace->name }}</strong>. This is where you'll connect your Instagram, LinkedIn, TikTok, X, Threads, and Facebook handles &mdash; EIAAW publishes through Blotato on your behalf.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #F3EDE0; border: 1px solid #D9CFBC; border-radius: 12px;">
                <tr>
                  <td style="padding: 24px;">
                    <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 12px;">
                      Your Blotato login
                    </div>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="6" style="font-size: 14px; color: #0F1A1D;">
                      <tr>
                        <td style="color: #6B7A7F; width: 110px; vertical-align: top; padding: 4px 0;">Login URL</td>
                        <td style="padding: 4px 0;"><a href="{{ $loginUrl }}" style="color: #11766A; text-decoration: underline;">{{ $loginUrl }}</a></td>
                      </tr>
                      <tr>
                        <td style="color: #6B7A7F; vertical-align: top; padding: 4px 0;">Email</td>
                        <td style="padding: 4px 0;"><strong>{{ $blotatoAccountEmail }}</strong></td>
                      </tr>
                      @if ($tempPassword)
                      <tr>
                        <td style="color: #6B7A7F; vertical-align: top; padding: 4px 0;">Temp&nbsp;password</td>
                        <td style="padding: 4px 0;">
                          <code style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 14px; background: #FFFFFF; border: 1px solid #D9CFBC; border-radius: 6px; padding: 4px 8px; display: inline-block;">{{ $tempPassword }}</code>
                        </td>
                      </tr>
                      @endif
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          @if ($tempPassword)
          <tr>
            <td style="padding: 20px 32px 0;">
              <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #B4412B;">
                <strong>Change this password on first login.</strong> Blotato's "Forgot password" link works from the login screen if you'd rather pick your own straight away.
              </p>
            </td>
          </tr>
          @endif

          <tr>
            <td style="padding: 28px 32px 0;">
              <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 12px;">
                What to do next
              </div>
              <ol style="margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.65; color: #2A3438;">
                <li>Log in to Blotato using the credentials above and connect the social handles for {{ $workspace->name }} (Instagram, LinkedIn, TikTok, X, Threads, Facebook &mdash; whichever you publish to).</li>
                <li>Come back to the EIAAW dashboard and click <strong>Verify connection</strong> &mdash; we'll check that the API key is live and pull in your connected handles automatically.</li>
                <li>Once verified, the Setup Wizard will unlock the rest of your brand onboarding.</li>
              </ol>
            </td>
          </tr>

          <tr>
            <td style="padding: 24px 32px 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td>
                    <a href="{{ $verifyUrl }}" style="display: inline-block; background: #0F1A1D; color: #FAF7F2; padding: 14px 24px; border-radius: 999px; font-size: 14px; font-weight: 500; text-decoration: none;">
                      Go to EIAAW &mdash; verify connection &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 32px;">
              <hr style="border: none; border-top: 1px dashed #D9CFBC; margin: 0 0 20px;">
              <p style="margin: 0; font-size: 12px; line-height: 1.55; color: #6B7A7F;">
                Trouble logging in to Blotato? Reply to this email or write to
                <a href="mailto:eiaawsolutions@gmail.com" style="color: #11766A;">eiaawsolutions@gmail.com</a>
                and we'll sort it out within the business day.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
