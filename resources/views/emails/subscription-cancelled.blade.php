<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Your EIAAW subscription is cancelling</title>
</head>
<body style="margin: 0; padding: 0; background: #FAF7F2; font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0F1A1D;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #FAF7F2; padding: 40px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width: 560px; background: #FFFFFF; border: 1px solid #D9CFBC; border-radius: 16px; overflow: hidden;">
          <tr>
            <td style="padding: 32px 32px 0;">
              <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #11766A;">
                EIAAW &middot; Cancellation confirmed
              </div>
              <h1 style="margin: 16px 0 0; font-size: 26px; font-weight: 600; letter-spacing: -0.02em; color: #0F1A1D; line-height: 1.2;">
                {{ $owner?->name ?? 'Hi' }}, we've scheduled your cancellation.
              </h1>
              <p style="margin: 16px 0 0; font-size: 15px; line-height: 1.55; color: #2A3438;">
                Your {{ $planName }} subscription for <strong>{{ $workspace->name }}</strong> is set to cancel
                @if ($endsAt)
                  on <strong>{{ $endsAt->format('F j, Y') }}</strong>.
                @else
                  at the end of your current billing period.
                @endif
                You keep full access until then, and you won't be charged again after that date.
              </p>
              <p style="margin: 12px 0 0; font-size: 14px; line-height: 1.55; color: #6B7A7F;">
                Changed your mind? You can reactivate any time before
                @if ($endsAt) {{ $endsAt->format('F j, Y') }} @else your period ends @endif
                from <a href="{{ $billingUrl }}" style="color: #11766A;">your billing page</a> — nothing is lost.
              </p>
              <p style="margin: 12px 0 0; font-size: 14px; line-height: 1.55; color: #6B7A7F;">
                After your access ends, your brands, content and history are preserved for 30 days so you can
                pick up where you left off if you decide to return.
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
