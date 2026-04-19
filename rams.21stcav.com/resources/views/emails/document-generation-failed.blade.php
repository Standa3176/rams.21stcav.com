<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $documentType }} Generation Failed — {{ $projectName }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px;color:#1a1a1a;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;">

    {{-- Header (failure-red per I-01 opt-in) --}}
    <tr>
        <td style="background:#b91c1c;padding:28px 32px;">
            <p style="margin:0;color:#fff;font-size:20px;font-weight:700;letter-spacing:.01em;">
                {{ config('rams.company_name') }}
            </p>
            <p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:14px;">
                {{ $documentType }} Generation Failed
            </p>
        </td>
    </tr>

    {{-- Body --}}
    <tr>
        <td style="padding:32px;">
            <p style="margin:0 0 16px;">Hi,</p>

            <p style="margin:0 0 16px;line-height:1.6;">
                The <strong>{{ $documentType }}</strong> generation for project
                <strong>{{ $projectName }}</strong>
                ({{ $projectRef ?: '—' }}) has failed after the configured retries.
            </p>

            {{-- Project detail table --}}
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="border:1.5px solid #e0e0e0;border-radius:6px;overflow:hidden;margin-bottom:24px;font-size:14px;">
                <tr style="background:#b91c1c;">
                    <th colspan="2" style="color:#fff;padding:10px 16px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">
                        Failure Details
                    </th>
                </tr>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:9px 16px;font-weight:600;color:#555;width:140px;">Document Type</td>
                    <td style="padding:9px 16px;">{{ $documentType }}</td>
                </tr>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:9px 16px;font-weight:600;color:#555;">Reference</td>
                    <td style="padding:9px 16px;">{{ $projectRef ?: '—' }}</td>
                </tr>
                <tr>
                    <td style="padding:9px 16px;font-weight:600;color:#555;">Project Name</td>
                    <td style="padding:9px 16px;">{{ $projectName }}</td>
                </tr>
            </table>

            <p style="margin:0 0 8px;"><strong>Error message:</strong></p>
            <pre style="background:#f6f6f6;padding:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;white-space:pre-wrap;word-wrap:break-word;border-radius:4px;font-size:13px;color:#333;border:1px solid #e0e0e0;margin:0 0 20px;">{{ $errorMessage ?: '(no error message captured — see laravel.log for stack trace)' }}</pre>

            <p style="margin:0 0 24px;line-height:1.6;">
                <a href="{{ $detailUrl }}"
                   style="display:inline-block;background:#007B8A;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:600;font-size:15px;">
                    Open the document detail page
                </a>
            </p>

            <p style="margin:0 0 16px;font-size:13px;color:#777;line-height:1.6;">
                Use the detail page to retry generation or inspect the project state.
                If the button above doesn't work, paste this link into your browser:<br>
                <a href="{{ $detailUrl }}" style="color:#007B8A;word-break:break-all;">{{ $detailUrl }}</a>
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
