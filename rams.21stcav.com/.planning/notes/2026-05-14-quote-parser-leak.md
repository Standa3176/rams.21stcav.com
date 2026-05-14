---
date: "2026-05-14 12:00"
promoted: false
---

Quote-parser leak: PDF overview text leaks "oyERVIEWTXTEND" / "OVERVIEWTXTEND" marker fragments into the phrased_overview field on the review form. Also: the bottom-of-PDF "Summary" section is being treated as a Room/Space row instead of being ignored. Source: Light Forms Ltd boardroom quote 21CQ30451-01-OPS reviewed during Phase 23 23-06 UAT (2026-05-14). Surface: project-packages/review.blade.php "Room / Space Overviews" section. Likely fault: QuoteParserService regex stripping markers + AI extraction prompt treating any OVERVIEWTITLE block as a room. Unrelated to Phase 23 renderer scope.
