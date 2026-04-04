@props([
    'action',
    'method'      => 'POST',
    'accept'      => '.pdf',
    'inputName'   => 'pdf',
    'label'       => 'Upload PDF',
    'description' => 'Drag & drop your PDF here, or click to browse',
    'enctype'     => 'multipart/form-data',
])

<div class="dash-upload-card" id="uploadCardZone">
    <form method="{{ strtoupper($method) === 'GET' ? 'GET' : 'POST' }}"
          action="{{ $action }}"
          enctype="{{ $enctype }}"
          id="uploadCardForm">

        @if(strtoupper($method) !== 'GET')
        @csrf
        @endif

        @if(!in_array(strtoupper($method), ['GET', 'POST']))
        @method($method)
        @endif

        <label class="dash-upload-card__label" for="uploadCardInput">
            <div class="dash-upload-card__icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.75" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <polyline points="12 12 12 18"/><polyline points="9 15 12 12 15 15"/>
                </svg>
            </div>

            <div class="dash-upload-card__heading">{{ $label }}</div>
            <div class="dash-upload-card__desc">{{ $description }}</div>
            <div class="dash-upload-card__type">PDF files only</div>

            <input type="file"
                   id="uploadCardInput"
                   name="{{ $inputName }}"
                   accept="{{ $accept }}"
                   class="dash-upload-card__input"
                   required>

            <div class="dash-upload-card__chosen" id="uploadCardChosen" hidden>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <span id="uploadCardFilename"></span>
            </div>
        </label>

        @if($slot->isNotEmpty())
        <div class="dash-upload-card__extra">{{ $slot }}</div>
        @endif

    </form>
</div>

<style>
.dash-upload-card {
    border: 2px dashed #E5E7EB;
    border-radius: 14px;
    background: #fff;
    transition: border-color .15s ease, background .15s ease;
}
.dash-upload-card:hover,
.dash-upload-card.drag-over {
    border-color: #178A95;
    background: #EBF6F7;
}
.dash-upload-card__label {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 3rem 2rem;
    cursor: pointer;
    text-align: center;
    gap: .5rem;
}
.dash-upload-card__icon {
    width: 60px;
    height: 60px;
    border-radius: 14px;
    background: #EBF6F7;
    color: #178A95;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: .5rem;
    transition: background .15s ease;
}
.dash-upload-card:hover .dash-upload-card__icon { background: #C8E9EC; }
.dash-upload-card__heading { font-size: 1rem; font-weight: 600; color: #1F2937; }
.dash-upload-card__desc    { font-size: .875rem; color: #6B7280; }
.dash-upload-card__type    { font-size: .75rem; color: #9CA3AF; font-weight: 500; text-transform: uppercase; letter-spacing: .05em; margin-top: .25rem; }
.dash-upload-card__input   { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
.dash-upload-card__chosen  {
    display: inline-flex !important;
    align-items: center;
    gap: .4rem;
    margin-top: .75rem;
    padding: .4rem .85rem;
    background: #EBF6F7;
    border: 1px solid #C8E9EC;
    border-radius: 9999px;
    font-size: .8125rem;
    font-weight: 500;
    color: #178A95;
}
.dash-upload-card__extra {
    padding: 0 2rem 1.5rem;
    display: flex;
    justify-content: center;
}
</style>

<script>
(function () {
    var input    = document.getElementById('uploadCardInput');
    var chosen   = document.getElementById('uploadCardChosen');
    var filename = document.getElementById('uploadCardFilename');
    var zone     = document.getElementById('uploadCardZone');
    if (!input) return;

    input.addEventListener('change', function () {
        if (input.files && input.files[0]) {
            filename.textContent = input.files[0].name;
            chosen.hidden = false;
        }
    });

    zone.addEventListener('dragover',  function (e) { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function ()  { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function (e) {
        e.preventDefault();
        zone.classList.remove('drag-over');
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            var dt = new DataTransfer();
            dt.items.add(e.dataTransfer.files[0]);
            input.files = dt.files;
            filename.textContent = e.dataTransfer.files[0].name;
            chosen.hidden = false;
        }
    });
}());
</script>
