<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RAMS Ready for Review — {{ $rams->project_name }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px;color:#1a1a1a;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;">

    {{-- Header --}}
    <tr>
        <td style="background:#007B8A;padding:28px 32px;">
            <p style="margin:0;color:#fff;font-size:20px;font-weight:700;letter-spacing:.01em;">
                {{ config('rams.company_name') }}
            </p>
            <p style="margin:6px 0 0;color:rgba(255,255,255,.8);font-size:14px;">
                RAMS Awaiting Review
            </p>
        </td>
    </tr>

    {{-- Body --}}
    <tr>
        <td style="padding:32px;">
            <p style="margin:0 0 16px;">Hi,</p>

            <p style="margin:0 0 16px;line-height:1.6;">
                A RAMS document for <strong>{{ $rams->project_name }}</strong>
                ({{ $rams->project_ref ?: '—' }}) is ready for review.
            </p>

            <p style="margin:0 0 16px;line-height:1.6;">
                Entered review queue:
                <strong>{{ optional($rams->updated_at)->format('j M Y H:i') }}</strong>
                (UK time)
            </p>

            <p style="margin:0 0 24px;line-height:1.6;">
                The extracted draft is awaiting your check before generation can proceed.
            </p>

            <p style="margin:0 0 24px;">
                <a href="{{ route('rams.review', $rams) }}"
                   style="display:inline-block;background:#007B8A;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;">
                    Open the review screen
                </a>
            </p>

            <p style="margin:0 0 16px;font-size:13px;color:#777;line-height:1.6;">
                If the button above doesn't work, paste this link into your browser:<br>
                <a href="{{ route('rams.review', $rams) }}" style="color:#007B8A;word-break:break-all;">{{ route('rams.review', $rams) }}</a>
            </p>

            <p style="margin:24px 0 0;font-size:14px;">
                Kind regards,<br>
                <strong>{{ config('rams.company_name') }}</strong>
            </p>
        </td>
    </tr>

    {{-- Footer --}}
    <tr>
        <td style="background:#f4f6f8;padding:16px 32px;border-top:1px solid #e8e8e8;">
            <p style="margin:0;font-size:12px;color:#999;text-align:center;">
                This email is confidential. If you are not the intended recipient,
                please notify us immediately and delete this message.
            </p>
        </td>
    </tr>

</table>
</td></tr>
</table>

</body>
</html>
