<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Survey Submitted</title>
</head>
<body style="margin:0;padding:0;background:#F0F4F5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px;color:#1F2937;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#F0F4F5;padding:32px 16px;">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #E5E7EB;">

                {{-- Header --}}
                <tr>
                    <td style="background:#0B3C45;padding:28px 32px 24px;">
                        <p style="margin:0 0 4px 0;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:rgba(255,255,255,.5);">
                            21st Century AV — RAMS Platform
                        </p>
                        <h1 style="margin:0;font-size:22px;font-weight:800;color:#ffffff;line-height:1.3;">
                            Site Survey Submitted
                        </h1>
                        <p style="margin:8px 0 0;font-size:14px;color:rgba(255,255,255,.75);">
                            {{ $survey->project_name }}
                            @if($survey->project_ref)
                                &nbsp;&middot;&nbsp;{{ $survey->project_ref }}
                            @endif
                        </p>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="padding:28px 32px 24px;">

                        <p style="margin:0 0 20px;font-size:15px;color:#374151;line-height:1.6;">
                            A site survey has been submitted by an engineer via the public link.
                            The details are shown below.
                        </p>

                        {{-- Summary table --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="border:1px solid #E5E7EB;border-radius:8px;overflow:hidden;margin-bottom:20px;font-size:14px;">
                            <tr style="background:#F3F6F7;">
                                <td style="padding:10px 16px;font-weight:700;color:#4B5563;width:40%;border-bottom:1px solid #E5E7EB;">Site Address</td>
                                <td style="padding:10px 16px;color:#1F2937;border-bottom:1px solid #E5E7EB;">
                                    {{ $survey->site_address ?: '—' }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:10px 16px;font-weight:700;color:#4B5563;border-bottom:1px solid #E5E7EB;">Surveyor</td>
                                <td style="padding:10px 16px;color:#1F2937;border-bottom:1px solid #E5E7EB;">
                                    {{ $survey->surveyor_name ?: '—' }}
                                </td>
                            </tr>
                            <tr style="background:#F3F6F7;">
                                <td style="padding:10px 16px;font-weight:700;color:#4B5563;border-bottom:1px solid #E5E7EB;">Survey Date</td>
                                <td style="padding:10px 16px;color:#1F2937;border-bottom:1px solid #E5E7EB;">
                                    {{ $survey->survey_date?->format('d M Y') ?: '—' }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:10px 16px;font-weight:700;color:#4B5563;">Rooms Completed</td>
                                <td style="padding:10px 16px;color:#1F2937;">
                                    <span style="display:inline-block;background:#D1FAE5;color:#065F46;padding:2px 10px;border-radius:20px;font-weight:700;">
                                        {{ $roomsCompleted }} room{{ $roomsCompleted !== 1 ? 's' : '' }}
                                    </span>
                                    marked complete
                                </td>
                            </tr>
                        </table>

                        {{-- Site Risks (conditional) --}}
                        @if($survey->site_risks)
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="border:1px solid #FDBA74;border-radius:8px;overflow:hidden;margin-bottom:16px;font-size:14px;">
                            <tr>
                                <td style="background:#FEE9CC;padding:8px 16px;font-weight:800;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#7C2D12;border-bottom:1px solid #FDBA74;">
                                    Site Risks
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:12px 16px;color:#374151;white-space:pre-wrap;line-height:1.6;">{{ $survey->site_risks }}</td>
                            </tr>
                        </table>
                        @endif

                        {{-- H&S Notes (conditional) --}}
                        @if($survey->h_and_s_notes)
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="border:1px solid #FDBA74;border-radius:8px;overflow:hidden;margin-bottom:16px;font-size:14px;">
                            <tr>
                                <td style="background:#FEE9CC;padding:8px 16px;font-weight:800;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#7C2D12;border-bottom:1px solid #FDBA74;">
                                    Health &amp; Safety Notes
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:12px 16px;color:#374151;white-space:pre-wrap;line-height:1.6;">{{ $survey->h_and_s_notes }}</td>
                            </tr>
                        </table>
                        @endif

                        {{-- Access Constraints (conditional) --}}
                        @if($survey->access_constraints)
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="border:1px solid #FDBA74;border-radius:8px;overflow:hidden;margin-bottom:16px;font-size:14px;">
                            <tr>
                                <td style="background:#FEE9CC;padding:8px 16px;font-weight:800;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#7C2D12;border-bottom:1px solid #FDBA74;">
                                    Access Constraints
                                </td>
                            </tr>
                            <tr>
                                <td style="padding:12px 16px;color:#374151;white-space:pre-wrap;line-height:1.6;">{{ $survey->access_constraints }}</td>
                            </tr>
                        </table>
                        @endif

                        {{-- CTA --}}
                        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;">
                            <tr>
                                <td align="center">
                                    <a href="{{ url('/site-surveys') }}"
                                       style="display:inline-block;background:#178A95;color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:8px;font-weight:700;font-size:15px;">
                                        Log in to RAMS to review the full survey
                                    </a>
                                </td>
                            </tr>
                        </table>

                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="background:#F3F6F7;border-top:1px solid #E5E7EB;padding:16px 32px;text-align:center;">
                        <p style="margin:0;font-size:12px;color:#9CA3AF;line-height:1.5;">
                            This email was sent automatically by the RAMS Platform for 21st Century AV Ltd.<br>
                            Please do not reply to this email.
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
