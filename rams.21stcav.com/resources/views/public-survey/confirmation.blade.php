<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survey Submitted — {{ $survey->project_name }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 17px; -webkit-font-smoothing: antialiased; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            font-size: 1rem;
            line-height: 1.5;
            color: #1F2937;
            background: #F0F4F5;
            min-height: 100vh;
        }
        a { color: #178A95; text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── Layout ───────────────────────────────────────────────────── */
        .wrap {
            max-width: 860px;
            margin: 0 auto;
            padding: 0 .875rem 2rem;
        }

        /* ── Header ───────────────────────────────────────────────────── */
        .survey-header {
            background: #0B3C45;
            color: #fff;
            padding: 1rem 1.25rem .75rem;
            margin-bottom: 1.25rem;
        }
        .survey-header__inner {
            max-width: 860px;
            margin: 0 auto;
        }
        .survey-header__brand {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .09em;
            text-transform: uppercase;
            color: rgba(255,255,255,.5);
            margin-bottom: .3rem;
        }
        .survey-header__title {
            font-size: 1.2rem;
            font-weight: 700;
            line-height: 1.3;
        }
        .survey-header__meta {
            font-size: .78rem;
            color: rgba(255,255,255,.65);
            margin-top: .25rem;
        }

        /* ── Confirmation card ────────────────────────────────────────── */
        .confirm-card {
            background: #fff;
            border-radius: 10px;
            border: 1.5px solid #e5e7eb;
            padding: 2rem 1.75rem;
            margin-top: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .confirm-icon {
            width: 56px;
            height: 56px;
            background: #D1FAE5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin-bottom: 1.25rem;
        }
        .confirm-heading {
            font-size: 1.375rem;
            font-weight: 700;
            color: #1F2937;
            margin-bottom: .5rem;
        }
        .confirm-body {
            font-size: .95rem;
            color: #374151;
            margin-bottom: 1.25rem;
            line-height: 1.6;
        }
        .confirm-meta {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 6px;
            padding: .875rem 1rem;
            font-size: .875rem;
            color: #374151;
            margin-bottom: 1.5rem;
        }
        .confirm-meta dt {
            font-weight: 600;
            color: #6B7280;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: .15rem;
        }
        .confirm-meta dd {
            margin: 0 0 .75rem;
        }
        .confirm-meta dd:last-child { margin-bottom: 0; }
        .btn-outline {
            display: inline-block;
            padding: .55rem 1.25rem;
            border: 1.5px solid #178A95;
            border-radius: 6px;
            color: #178A95;
            font-weight: 600;
            font-size: .875rem;
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .btn-outline:hover {
            background: #178A95;
            color: #fff;
            text-decoration: none;
        }
    </style>
</head>
<body>

{{-- Header --}}
<div class="survey-header">
    <div class="survey-header__inner">
        <div class="survey-header__brand">21st Century AV — Site Survey</div>
        <div class="survey-header__title">{{ $survey->project_name }}</div>
        <div class="survey-header__meta">
            @if($survey->client_name){{ $survey->client_name }}@endif
            @if($survey->client_name && $survey->site_address) &nbsp;·&nbsp; @endif
            @if($survey->site_address){{ $survey->site_address }}@endif
        </div>
    </div>
</div>

<div class="wrap">

    <div class="confirm-card">
        <div class="confirm-icon">✓</div>
        <h1 class="confirm-heading">Survey Submitted</h1>
        <p class="confirm-body">Your survey responses have been recorded. The project team will review your submission.</p>

        <dl class="confirm-meta">
            <dt>Project</dt>
            <dd>{{ $survey->project_name }}</dd>
            @if($survey->site_address)
            <dt>Site</dt>
            <dd>{{ $survey->site_address }}</dd>
            @endif
            @if($survey->submitted_at)
            <dt>Submitted</dt>
            <dd>{{ $survey->submitted_at->format('d M Y \a\t H:i') }}</dd>
            @endif
        </dl>

        <a href="{{ route('survey.show', $token) }}" class="btn-outline">
            View submitted survey
        </a>
    </div>

</div>

</body>
</html>
