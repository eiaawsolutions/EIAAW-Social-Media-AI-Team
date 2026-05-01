<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Welcome to EIAAW Social Media Team</title>
</head>
<body style="margin: 0; padding: 0; background: #FAF7F2; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0F1A1D;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #FAF7F2; padding: 40px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width: 560px; background: #FFFFFF; border: 1px solid #D9CFBC; border-radius: 16px; overflow: hidden;">
          <tr>
            <td style="padding: 32px 32px 0;">
              <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #11766A;">
                EIAAW SOLUTIONS &middot; Social Media Team
              </div>
              <h1 style="margin: 16px 0 0; font-size: 28px; font-weight: 600; letter-spacing: -0.02em; color: #0F1A1D; line-height: 1.2;">
                Welcome to EIAAW, {{ $user->name }}.
              </h1>
              <p style="margin: 16px 0 0; font-size: 15px; line-height: 1.55; color: #2A3438;">
                Your <strong>{{ $planName }}</strong> workspace <strong>{{ $workspace->name }}</strong> is ready. Your 14-day free trial has started — your card won't be charged until {{ optional($trialEndsAt)->format('F j, Y') }}.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #F3EDE0; border: 1px solid #D9CFBC; border-radius: 12px;">
                <tr>
                  <td style="padding: 24px;">
                    <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 12px;">
                      Your login details
                    </div>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="6" style="font-size: 14px; color: #0F1A1D;">
                      <tr>
                        <td style="color: #6B7A7F; width: 90px; vertical-align: top; padding: 4px 0;">Login URL</td>
                        <td style="padding: 4px 0;"><a href="{{ $loginUrl }}" style="color: #11766A; text-decoration: underline;">{{ $loginUrl }}</a></td>
                      </tr>
                      <tr>
                        <td style="color: #6B7A7F; vertical-align: top; padding: 4px 0;">Email</td>
                        <td style="padding: 4px 0;"><strong>{{ $user->email }}</strong></td>
                      </tr>
                      <tr>
                        <td style="color: #6B7A7F; vertical-align: top; padding: 4px 0;">Password</td>
                        <td style="padding: 4px 0;">
                          <code style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 14px; background: #FFFFFF; border: 1px solid #D9CFBC; border-radius: 6px; padding: 4px 8px; display: inline-block;">{{ $tempPassword }}</code>
                        </td>
                      </tr>
                      <tr>
                        <td style="color: #6B7A7F; vertical-align: top; padding: 4px 0;">Plan</td>
                        <td style="padding: 4px 0;">{{ $planName }} &middot; {{ $trialDays }}-day free trial</td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding: 24px 32px 0;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                  <td>
                    <a href="{{ $loginUrl }}" style="display: inline-block; background: #0F1A1D; color: #FAF7F2; padding: 14px 24px; border-radius: 999px; font-size: 14px; font-weight: 500; text-decoration: none;">
                      Open my dashboard &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 0;">
              <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #B4412B;">
                <strong>Please change your password after your first login.</strong> Forgot it later? Use the
                <a href="{{ $forgotUrl }}" style="color: #11766A;">password reset</a> link on the login page.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 32px;">
              <hr style="border: none; border-top: 1px dashed #D9CFBC; margin: 0 0 20px;">
              <p style="margin: 0; font-size: 12px; line-height: 1.55; color: #6B7A7F;">
                EIAAW Social Media Team &middot; AI-Human Partnerships<br>
                <a href="https://eiaawsolutions.com" style="color: #6B7A7F;">eiaawsolutions.com</a>
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
