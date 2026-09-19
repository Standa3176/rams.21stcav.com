{{--
    Cockpit state pip — 45-UI-SPEC.md § State Vocabulary.

    Three states, each carrying meaning on THREE independent channels:
      shape  · done = solid circle, attention = diamond, waiting = hollow circle
      text   · the CALLER always renders visible status text beside the pip
      a11y   · role="img" + an aria-label from the state vocabulary

    A pip is never the sole carrier of state, and it appears on a drawer
    <summary> ONLY — visit rows carry status text and chips, never a pip.
    Purely presentational: props in, markup out, no query.
    All colour comes from --cav-* tokens in cav-tokens.css; a hex here would
    be a contract violation.
--}}
@props(['state' => 'waiting'])

@php
    $states = [
        'done'      => ['cav-pip--done', 'Complete'],
        'attention' => ['cav-pip--attn', 'Needs attention'],
        'waiting'   => ['cav-pip--wait', 'Not started'],
    ];

    [$pipClass, $pipLabel] = $states[$state] ?? $states['waiting'];
@endphp

<span {{ $attributes->merge(['class' => 'cav-pip ' . $pipClass]) }}
      role="img"
      aria-label="{{ $pipLabel }}"></span>
