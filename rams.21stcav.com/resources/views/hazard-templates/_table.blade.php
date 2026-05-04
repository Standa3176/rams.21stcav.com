{{--
    Hazard template table partial.

    Variables:
      $templates  Collection<HazardTemplate>
      $canEdit    bool — show Edit / Delete buttons
--}}
<table class="data-table" style="font-size:.83rem;">
    <thead>
        <tr>
            <th>Hazard</th>
            <th style="text-align:center; white-space:nowrap;">Pre Risk</th>
            <th style="text-align:center; white-space:nowrap;">Post Risk</th>
            <th style="text-align:center;">Controls</th>
            @if ($canEdit) <th style="width:90px;"></th> @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($templates as $t)
        @php
            $preScore   = $t->pre_likelihood  * $t->pre_severity;
            $postScore  = $t->post_likelihood * $t->post_severity;
            // Colour bands: ≥15 red / ≥8 orange / <8 green (pre); ≥8 orange / ≥4 yellow / <4 green (post)
            $preBg  = $preScore  >= 15 ? '#fde8e8' : ($preScore  >= 8 ? '#fef3e0' : '#e9f7ef');
            $preFg  = $preScore  >= 15 ? '#c0392b' : ($preScore  >= 8 ? '#e67e22' : '#27ae60');
            $postBg = $postScore >= 8  ? '#fef3e0' : ($postScore >= 4 ? '#fefbe0' : '#e9f7ef');
            $postFg = $postScore >= 8  ? '#e67e22' : ($postScore >= 4 ? '#b7950b' : '#27ae60');
            $ctrlCount = count($t->controls ?? []);
        @endphp
        <tr>
            <td>
                <strong style="font-size:.85rem;">{{ $t->name }}</strong>
                @if ($t->description)
                    <div style="font-size:.76rem; color:#777; margin-top:1px; line-height:1.35;">
                        {{ Str::limit($t->description, 90) }}
                    </div>
                @endif

                {{-- Expandable controls panel --}}
                @if ($ctrlCount > 0)
                <div id="ctrl-{{ $t->id }}" class="hazard-ctrl-panel">
                    <ol>
                        @foreach ($t->controls as $ctrl)
                            <li>{{ $ctrl }}</li>
                        @endforeach
                    </ol>
                </div>
                @endif
            </td>

            {{-- Pre-controls risk score --}}
            <td style="text-align:center;">
                <span class="risk-badge" style="background:{{ $preBg }}; color:{{ $preFg }};">
                    {{ $preScore }}
                </span>
                <div style="font-size:.68rem; color:#aaa; margin-top:1px;">
                    {{ $t->pre_likelihood }}×{{ $t->pre_severity }}
                </div>
            </td>

            {{-- Post-controls risk score --}}
            <td style="text-align:center;">
                <span class="risk-badge" style="background:{{ $postBg }}; color:{{ $postFg }};">
                    {{ $postScore }}
                </span>
                <div style="font-size:.68rem; color:#aaa; margin-top:1px;">
                    {{ $t->post_likelihood }}×{{ $t->post_severity }}
                </div>
            </td>

            {{-- Controls count (toggle button) --}}
            <td style="text-align:center;">
                @if ($ctrlCount > 0)
                    <button type="button"
                            onclick="toggleControls({{ $t->id }})"
                            style="background:none; border:none; color:#007B8A; cursor:pointer; font-size:.8rem; padding:0; white-space:nowrap;">
                        {{ $ctrlCount }} ▾
                    </button>
                @else
                    <span style="color:#ccc; font-size:.8rem;">0</span>
                @endif
            </td>

            {{-- Actions --}}
            @if ($canEdit)
            <td>
                <div style="display:flex; gap:.3rem; justify-content:flex-end;">
                    <a href="{{ route('hazard-templates.edit', $t) }}"
                       class="btn btn-outline btn-sm">Edit</a>
                    <form method="POST" action="{{ route('hazard-templates.destroy', $t) }}"
                          data-confirm="Delete template: {{ $t->name }}?"
                          data-confirm-label="Delete"
                          data-confirm-danger="1"
                          style="margin:0;">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="btn btn-outline btn-sm"
                                style="color:#c0392b; border-color:#c0392b;">&#10005;</button>
                    </form>
                </div>
            </td>
            @endif
        </tr>
        @endforeach
    </tbody>
</table>
