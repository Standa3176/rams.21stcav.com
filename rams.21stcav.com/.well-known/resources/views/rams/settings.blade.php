@extends('layouts.app')

@section('title', 'RAMS — AI Provider Settings')

@section('content')

@php
$currentProvider = $currentProvider ?? 'claude';
$claudeModel     = $claudeModel ?? 'claude-sonnet-4-6';
$openaiModel     = $openaiModel ?? 'gpt-4o';
$openaiEndpoint  = $openaiEndpoint ?? 'https://api.openai.com/v1/chat/completions';
$claudeKeySet    = $claudeKeySet ?? false;
$openaiKeySet    = $openaiKeySet ?? false;
@endphp

<div class="page-header">
    <h1 class="page-title">RAMS — AI Provider Settings</h1>
    <a href="{{ route('rams.index') }}" class="btn btn-outline btn-sm">← Back to list</a>
</div>

{{-- Flash messages --}}
@if (session('success'))
<div class="alert alert-success">{{ session('success') }}</div>
@endif

@if (session('error'))
<div class="alert alert-error">{{ session('error') }}</div>
@endif

@if ($errors->any())
<div class="alert alert-error">
    <strong>Please correct the following errors:</strong>
    <ul>
        @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

{{-- Active provider banner --}}
<div class="section-block" style="background:#f0fbfc;border:1px solid #b2dfe5;border-radius:6px;padding:1.25rem 1.5rem;margin-bottom:1.5rem;">

    <strong style="color:#007B8A;">Currently Active:</strong>

    <span style="font-weight:600;color:#333;">

        @if($currentProvider === 'claude')
            Claude — <code>{{ $claudeModel }}</code>
        @elseif($currentProvider === 'openai')
            OpenAI — <code>{{ $openaiModel }}</code>
        @else
            Custom Provider
        @endif

    </span>

    <span id="active-status-badge"
          style="margin-left:10px;font-size:.8rem;padding:.2rem .6rem;border-radius:4px;background:#eee;">
        checking…
    </span>

</div>

<div class="alert alert-info">
API keys are stored in the <code>.env</code> file.
Leave blank to keep existing keys.
</div>

<form method="POST" action="{{ route('rams.settings.save') }}">
@csrf

{{-- Provider selection --}}
<div class="section-block">

<h2 class="section-heading">Default Provider</h2>

<select name="default_provider"
        id="default_provider"
        class="form-control"
        onchange="toggleProviderPanels(this.value)"
        style="max-width:300px;">

<option value="claude" {{ $currentProvider=='claude'?'selected':'' }}>Claude</option>
<option value="openai" {{ $currentProvider=='openai'?'selected':'' }}>OpenAI</option>
<option value="custom" {{ $currentProvider=='custom'?'selected':'' }}>Custom</option>

</select>

</div>


{{-- Claude settings --}}
<div class="section-block" id="panel-claude">

<h2 class="section-heading">Claude (Anthropic)</h2>

<div style="margin-bottom:10px;">

<span style="font-size:.8rem;padding:.25rem .6rem;border-radius:4px;
{{ $claudeKeySet ? 'background:#d4edda;color:#155724' : 'background:#f8d7da;color:#721c24' }}">

{{ $claudeKeySet ? '✓ API key set' : '✗ No API key' }}

</span>

<button type="button"
        onclick="testConnection('claude')"
        id="test-btn-claude"
        class="btn btn-outline btn-sm">

Test Connection

</button>

</div>

<div id="test-result-claude" style="display:none;"></div>

<div class="form-grid-2">

<div class="form-group">

<label>API Key</label>

<input type="password"
       name="claude_api_key"
       class="form-control"
       placeholder="{{ $claudeKeySet ? '•••••• existing key' : 'sk-ant-...' }}">

</div>

<div class="form-group">

<label>Model</label>

<input type="text"
       name="claude_model"
       class="form-control"
       value="{{ $claudeModel }}">

</div>

</div>

</div>


{{-- OpenAI settings --}}
<div class="section-block" id="panel-openai">

<h2 class="section-heading">OpenAI</h2>

<div style="margin-bottom:10px;">

<span style="font-size:.8rem;padding:.25rem .6rem;border-radius:4px;
{{ $openaiKeySet ? 'background:#d4edda;color:#155724' : 'background:#f8d7da;color:#721c24' }}">

{{ $openaiKeySet ? '✓ API key set' : '✗ No API key' }}

</span>

<button type="button"
        onclick="testConnection('openai')"
        id="test-btn-openai"
        class="btn btn-outline btn-sm">

Test Connection

</button>

</div>

<div id="test-result-openai" style="display:none;"></div>

<div class="form-grid-2">

<div class="form-group">

<label>API Key</label>

<input type="password"
       name="openai_api_key"
       class="form-control"
       placeholder="{{ $openaiKeySet ? '•••••• existing key' : 'sk-...' }}">

</div>

<div class="form-group">

<label>Model</label>

<input type="text"
       name="openai_model"
       class="form-control"
       value="{{ $openaiModel }}">

</div>

</div>

<div class="form-group">

<label>Endpoint</label>

<input type="text"
       name="openai_endpoint"
       class="form-control"
       value="{{ $openaiEndpoint }}">

</div>

</div>


<div class="section-block">

<button type="submit" class="btn btn-teal">
Save Settings
</button>

</div>

</form>

@endsection


@push('scripts')

<script>

const TEST_URL = "{{ route('rams.settings.test') }}";
const PROVIDER = "{{ $currentProvider }}";
const CSRF     = document.querySelector('meta[name="csrf-token"]')?.content || '';

function toggleProviderPanels(provider)
{

['claude','openai'].forEach(p=>{

const panel=document.getElementById('panel-'+p);

if(!panel)return;

panel.style.borderLeft =
    p===provider ? '4px solid #007B8A' : '4px solid transparent';

});

}

toggleProviderPanels(PROVIDER);


async function testConnection(provider)
{

const btn=document.getElementById('test-btn-'+provider);
const result=document.getElementById('test-result-'+provider);

btn.disabled=true;
btn.textContent='Testing...';

try{

const res=await fetch(TEST_URL,{
method:'POST',
headers:{
'Content-Type':'application/json',
'X-CSRF-TOKEN':CSRF
},
body:JSON.stringify({provider})
});

const data=await res.json();

result.style.display='block';

if(data.ok){
result.style.background='#d4edda';
result.textContent='✓ '+data.message;
}
else{
result.style.background='#f8d7da';
result.textContent='✗ '+data.message;
}

}catch(e){

result.style.display='block';
result.style.background='#f8d7da';
result.textContent='Connection failed';

}

btn.disabled=false;
btn.textContent='Test Connection';

}

(function(){

const badge=document.getElementById('active-status-badge');

fetch(TEST_URL,{
method:'POST',
headers:{
'Content-Type':'application/json',
'X-CSRF-TOKEN':CSRF
},
body:JSON.stringify({provider:PROVIDER})
})
.then(r=>r.json())
.then(data=>{

badge.textContent=data.ok?'✓ Connected':'✗ Not connected';

badge.style.background=data.ok?'#d4edda':'#f8d7da';

})
.catch(()=>{

badge.textContent='✗ Check failed';

});

})();

</script>

@endpush