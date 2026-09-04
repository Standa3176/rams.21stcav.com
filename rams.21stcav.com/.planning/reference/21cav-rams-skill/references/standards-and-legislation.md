# Standards, legislation and CDM — wording guidance

**The list of standards, the "never cite" list, the RCS Workplace Exposure
Limit, the RIDDOR line and the CDM notifiability thresholds are supplied from the
controlled standards library** (injected into this prompt from
`data/standards-library.json`, the same source the generator's validator checks
against). Do not re-type a standards list here or in the document — cite only the
designations from that library, by their exact wording. If a standard the job
needs is not in the library, name the instrument in plain words (e.g. "the
manufacturer's published installation and load data") rather than inventing a
code, an edition or an ACOP number.

Cite only what the job actually involves — do not pad the table. Any standard you
rely on in the body (QA, method, notes) must also appear in the Section 3
standards table.

## CDM 2015 — how to word the duty holders

The library gives the facts (the PD/PC trigger, the F10 duty, the notifiability
threshold, who prepares the construction phase plan, the contractor's regulation).
This is how to *word* them so a competent reviewer accepts it:

- **Do not state unequivocally that "21CAV is the sole contractor".** At
  preliminary stage the contractor make-up is usually unconfirmed, so word it:
  *"21CAV is currently anticipated to be the sole contractor for the AV
  installation scope. The client shall confirm whether the overall project
  involves, or is likely to involve, more than one contractor before works
  commence."*
- The CDM **Client row uses the known client's name** (never "To be confirmed"
  alongside the client's own name). Leave "Accepted by (Client)" blank for the
  client to sign — never auto-fill it with the site contact.
- The **Contractor / 21CAV row is conditional**: *"If sole contractor: prepares
  and implements the Construction Phase Plan. If multiple contractors: works to
  the Principal Contractor's Construction Phase Plan and site arrangements."*
- Never write that the client "retains Principal Designer responsibilities" or
  that 21CAV "discharges its duties under Regulations 4 or 5" — both are wrong;
  contractor duties sit under Regulation 15.
- Never write "the Principal Contractor must notify HSE" — the F10 duty is the
  Client's (it may be submitted on the Client's behalf). Most single-visit AV
  installs are not notifiable — say so rather than implying an F10 is always
  needed, and judge notifiability on the whole project, not 21CAV's one day.

## Confined spaces

Ceiling voids and comms rooms are **not** confined spaces under the Confined
Spaces Regulations 1997. Title the hazard "Restricted access and ceiling void
working" and do not cite the confined-spaces ACOP (L101) for this work.
