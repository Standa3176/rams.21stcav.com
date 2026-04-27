{{-- Reusable blank-fill grid shared by the fallback (no DB rooms) and per-room
     sections. Structured as labeled rows so every measurement / Y/N / free
     text has its own writable slot. --}}
@php($line = \App\Support\SurveyPdfHelpers::BLANK_LINE)

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
<div style="font-size:7.5pt;color:#666;margin-bottom:2pt;">Sketch (mark doors, windows, power, display, rack — grid = 5mm):</div>
<div class="sketch-box"></div>
