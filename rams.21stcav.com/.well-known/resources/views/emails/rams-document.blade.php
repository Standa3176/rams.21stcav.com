<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RAMS Document — {{ $rams->project_name }}</title>
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
                Risk Assessment &amp; Method Statement
            </p>
        </td>
    </tr>

    {{-- Body --}}
    <tr>
        <td style="padding:32px;">
            <p style="margin:0 0 16px;">Dear {{ $recipientName }},</p>

            <p style="margin:0 0 16px;line-height:1.6;">
                Please find attached the RAMS document for the project detailed below.
                The document is attached as a Word file (.docx) for your review.
            </p>

            @if ($senderNote)
            <div style="background:#f0fafb;border-left:4px solid #007B8A;padding:12px 16px;margin:0 0 20px;border-radius:0 4px 4px 0;">
                <p style="margin:0;font-size:14px;color:#444;white-space:pre-line;">{{ $senderNote }}</p>
            </div>
            @endif

            {{-- Project detail table --}}
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="border:1.5px solid #e0e0e0;border-radius:6px;overflow:hidden;margin-bottom:24px;font-size:14px;">
                <tr style="background:#007B8A;">
                    <th colspan="2" style="color:#fff;padding:10px 16px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">
                        Project Details
                    </th>
                </tr>
                @if ($rams->project_ref)
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:9px 16px;font-weight:600;color:#555;width:140px;">Reference</td>
                    <td style="padding:9px 16px;">{{ $rams->project_ref }}</td>
                </tr>
                @endif
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:9px 16px;font-weight:600;color:#555;">Project Name</td>
                    <td style="padding:9px 16px;">{{ $rams->project_name }}</td>
                </tr>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:9px 16px;font-weight:600;color:#555;">Client</td>
                    <td style="padding:9px 16px;">{{ $rams->client_name }}</td>
                </tr>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:9px 16px;font-weight:600;color:#555;">Site Address</td>
                    <td style="padding:9px 16px;">{{ $rams->site_address }}</td>
                </tr>
                <tr>
                    <td style="padding:9px 16px;font-weight:600;color:#555;">Hazards</td>
                    <td style="padding:9px 16px;">{{ count($rams->generated_data['hazards'] ?? []) }} identified</td>
                </tr>
            </table>

            <p style="margin:0 0 24px;font-size:13px;color:#777;line-height:1.6;">
                If you have any questions regarding this document, please do not hesitate to contact us.
            </p>

            <p style="margin:0;font-size:14px;">
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
