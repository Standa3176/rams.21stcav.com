{{-- Reusable blank-fill grid shared by the fallback (no DB rooms) and per-room
     sections. Structured as labeled rows so every measurement / Y/N / free
     text has its own writable slot. --}}
@php($line = \App\Support\SurveyPdfHelpers::BLANK_LINE)

<h3>Site Conditions</h3>
<table>
    <tr>
        <td class="label-sm">Room type / use</td><td colspan="3">{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Seating capacity</td><td>{!! $line !!}</td>
        <td class="label-sm">Viewing distance (m)</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Width (m)</td><td>{!! $line !!}</td>
        <td class="label-sm">Depth (m)</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Ceiling height (m)</td><td>{!! $line !!}</td>
        <td class="label-sm">Ceiling type</td>
        <td>&#9744; Concrete &nbsp; &#9744; Suspended &nbsp; &#9744; Plasterboard &nbsp; &#9744; Open &nbsp; &#9744; Other: {!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Wall construction</td>
        <td colspan="3">&#9744; Masonry &nbsp; &#9744; Stud/Plasterboard &nbsp; &#9744; Timber frame &nbsp; &#9744; Glass partition &nbsp; &#9744; Other: {!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Floor type</td>
        <td colspan="3">&#9744; Concrete &nbsp; &#9744; Carpet &nbsp; &#9744; Tiles &nbsp; &#9744; Raised access &nbsp; &#9744; Other: {!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Display mount height</td>
        <td>Centre from floor (mm): {!! $line !!}</td>
        <td class="label-sm">Distance to furthest seat (m)</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Power available</td><td>&#9744; Yes &nbsp; &#9744; No</td>
        <td class="label-sm">Outlets at equipment wall</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Nearest socket to display (m)</td><td>{!! $line !!}</td>
        <td class="label-sm">New socket required?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
    </tr>
    <tr>
        <td class="label-sm">Spare breaker capacity</td><td>&#9744; Yes &nbsp; &#9744; No</td>
        <td class="label-sm">UPS / clean supply present?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
    </tr>
    <tr>
        <td class="label-sm">Network available</td><td>&#9744; Yes &nbsp; &#9744; No</td>
        <td class="label-sm">Active ports</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">PoE switch present?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
        <td class="label-sm">Dedicated VLAN?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
    </tr>
    <tr>
        <td class="label-sm">Switch location</td><td colspan="3">{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Cable route</td>
        <td colspan="3">&#9744; Containment &nbsp; &#9744; Floor boxes &nbsp; &#9744; Ceiling void &nbsp; &#9744; Surface trunking &nbsp; &#9744; Underfloor void &nbsp; Est. run (m): {!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Access / hazards</td>
        <td colspan="3">&#9744; Working at height &nbsp; &#9744; Out-of-hours &nbsp; &#9744; Permits &nbsp; &#9744; Manual handling &nbsp; &#9744; Asbestos register checked &nbsp; &#9744; Live working</td>
    </tr>
    <tr>
        <td class="label-sm">Existing AV to retain</td><td colspan="3">{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Existing AV to remove</td><td colspan="3">{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Photos captured (ref)</td><td colspan="3">{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Items not confirmed / blockers</td><td colspan="3">{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Notes</td><td colspan="3">{!! $line !!}</td>
    </tr>
</table>

{{-- ─────────────────────────────────────────────────────────────────────
     ENGINEER FEEDBACK — added by quick task 260503-w77 to mirror the
     same fields surfaced in the digital wizard (260503-rgg / -u2x).
     Keeps paper → digital transcription 1:1 when engineers work offline.
     ───────────────────────────────────────────────────────────────────── --}}

<h3>Mounting Heights</h3>
<table>
    <tr>
        <td class="label-sm">Screen (m)</td><td>{!! $line !!}</td>
        <td class="label-sm">Camera (m)</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Booking panel (m)</td><td>{!! $line !!}</td>
        <td class="label-sm">Speaker (m)</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Other 1</td>
        <td colspan="3">Label: {!! $line !!} &nbsp;&nbsp; Height (m): {!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Other 2</td>
        <td colspan="3">Label: {!! $line !!} &nbsp;&nbsp; Height (m): {!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Other 3</td>
        <td colspan="3">Label: {!! $line !!} &nbsp;&nbsp; Height (m): {!! $line !!}</td>
    </tr>
</table>

<h3>Working at Height — Methods Required</h3>
<table>
    <tr>
        <td colspan="4">
            &#9744; Ladder &nbsp;&nbsp; &#9744; Podium &nbsp;&nbsp; &#9744; Tower &nbsp;&nbsp; &#9744; MEWP &nbsp;&nbsp; &#9744; Scaffold &nbsp;&nbsp; &#9744; N/A
            <span style="color:#888;font-size:7.5pt;">&nbsp;(tick all that apply)</span>
        </td>
    </tr>
</table>

<h3>Cable Routes Planned</h3>
<table>
    <tr>
        <th style="width:24%;">Category</th>
        <th style="width:26%;">From</th>
        <th style="width:26%;">To</th>
        <th style="width:12%;">Length (m)</th>
        <th style="width:12%;">Notes</th>
    </tr>
    @for ($cr = 0; $cr < 4; $cr++)
        <tr>
            <td style="font-size:7.5pt;">&#9744; Ceiling spk &nbsp; &#9744; Desk &nbsp; &#9744; Mic &nbsp; &#9744; Booking &nbsp; &#9744; Screen &nbsp; &#9744; Rack→Room</td>
            <td>{!! $line !!}</td>
            <td>{!! $line !!}</td>
            <td>{!! $line !!}</td>
            <td>{!! $line !!}</td>
        </tr>
    @endfor
</table>

<h3>Wall Construction &amp; Prep</h3>
<table>
    <tr>
        <td class="label-sm">Wall construction (multi)</td>
        <td colspan="3">
            &#9744; Ply-lined &nbsp; &#9744; Solid &nbsp; &#9744; Plasterboard &nbsp; &#9744; Masonry &nbsp; &#9744; Metal stud &nbsp; &#9744; Concrete
        </td>
    </tr>
    <tr>
        <td class="label-sm">Reinforcement needed?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
        <td class="label-sm">Chase out needed?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
    </tr>
    <tr>
        <td class="label-sm">Conduit needed?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
        <td class="label-sm">Notes</td><td>{!! $line !!}</td>
    </tr>
</table>

<h3>Table Info</h3>
<table>
    <tr>
        <td class="label-sm">Has table grommets?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
        <td class="label-sm">Grommet count</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Grommet size</td>
        <td>&#9744; Small &nbsp; &#9744; Standard &nbsp; &#9744; Large</td>
        <td class="label-sm">Notes</td><td>{!! $line !!}</td>
    </tr>
</table>

<h3>Floor Box Info</h3>
<table>
    <tr>
        <td class="label-sm">Has floor box?</td><td>&#9744; Yes &nbsp; &#9744; No</td>
        <td class="label-sm">Power outlets</td><td>{!! $line !!}</td>
    </tr>
    <tr>
        <td class="label-sm">Data outlets</td><td>{!! $line !!}</td>
        <td class="label-sm">Cable space</td>
        <td>&#9744; Tight &nbsp; &#9744; Adequate &nbsp; &#9744; Spacious</td>
    </tr>
    <tr><td class="label-sm">Notes</td><td colspan="3">{!! $line !!}</td></tr>
</table>

<h3>Brackets Required</h3>
<table>
    <tr>
        <th style="width:35%;">Equipment</th>
        <th style="width:30%;">Bracket model</th>
        <th style="width:15%;">Pull-out?</th>
        <th style="width:20%;">Notes</th>
    </tr>
    @for ($b = 0; $b < 4; $b++)
        <tr>
            <td>{!! $line !!}</td>
            <td>{!! $line !!}</td>
            <td>&#9744; Yes &nbsp; &#9744; No</td>
            <td>{!! $line !!}</td>
        </tr>
    @endfor
</table>

<div style="font-size:7.5pt;color:#666;margin-bottom:2pt;">Sketch (mark doors, windows, power, display, rack — grid = 5mm):</div>
<div class="sketch-box"></div>
