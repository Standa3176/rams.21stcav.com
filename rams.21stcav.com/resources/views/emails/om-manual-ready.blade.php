<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>O&M Manual Ready — {{ $manual->project_name }}</title>
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
                Operation &amp; Maintenance Manual
            </p>
        </td>
    </tr>

    {{-- Body --}}
    <tr>
        <td style="padding:32px;">
            <p style="margin:0 0 16px;">Hi,</p>

            <p style="margin:0 0 16px;line-height:1.6;">
                Your O&amp;M Manual for
                <strong>{{ $manual->project_name }}</strong>@if($manual->project_ref) ({{ $manual->project_ref }})@endif
                has been generated and is ready.
            </p>

            <p style="margin:0 0 16px;">
                Generated: {{ optional($manual->updated_at)->format('j M Y H:i') }} (UK time)
            </p>

            @if($manual->filename)
                <p style="margin:0 0 16px;">The O&amp;M Manual is attached to this email.</p>
            @else
                <p style="margin:0 0 16px;">Download from the dashboard:</p>
            @endif

            <p style="margin:0 0 16px;">
                <a href="{{ route('om-manuals.edit', $manual) }}" style="color:#007B8A;">View in RAMS Platform</a>
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
                This email and its attachments are confidential. If you are not the intended recipient,
                please notify us immediately and delete this message.
            </p>
        </td>
    </tr>

</table>
</td></tr>
</table>

</body>
</html>
