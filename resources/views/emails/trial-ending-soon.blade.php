<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your EIAAW trial ends soon</title>
</head>
<body style="margin: 0; padding: 0; background: #FAF7F2; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0F1A1D;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #FAF7F2; padding: 40px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width: 560px; background: #FFFFFF; border: 1px solid #D9CFBC; border-radius: 16px; overflow: hidden;">
          <tr>
            <td style="padding: 32px 32px 0;">
              <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #11766A;">
                EIAAW &middot; Trial reminder
              </div>
              <h1 style="margin: 16px 0 0; font-size: 26px; font-weight: 600; letter-spacing: -0.02em; color: #0F1A1D; line-height: 1.2;">
                {{ $owner?->name ?? 'Hi' }}, your trial wraps in 3 days.
              </h1>
              <p style="margin: 16px 0 0; font-size: 15px; line-height: 1.55; color: #2A3438;">
                {{ $workspace->name }} ({{ $planName }}) ends its trial on
                <strong>{{ optional($trialEndsAt)->format('F j, Y') }}</strong>.
                Your saved card will be charged
                @if ($priceMyr) <strong>RM {{ number_format($priceMyr, 0) }}</strong> @endif
                on that day and your subscription will continue automatically.
              </p>
              <p style="margin: 12px 0 0; font-size: 14px; line-height: 1.55; color: #6B7A7F;">
                Nothing to do if you want to keep going. To change your plan or cancel, visit
                <a href="{{ $billingUrl }}" style="color: #11766A;">your billing page</a>.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding: 28px 32px 0;">
              <a href="{{ $billingUrl }}" style="display: inline-block; background: #0F1A1D; color: #FAF7F2; padding: 14px 24px; border-radius: 999px; font-size: 14px; font-weight: 500; text-decoration: none;">
                Manage billing &rarr;
              </a>
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
