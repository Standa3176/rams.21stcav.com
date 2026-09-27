<?php

namespace App\Support\Cockpit;

/**
 * CockpitCreationOutcome — WHAT HAPPENED TO EACH OF DOCUMENT / VISIT / LINK,
 * AS DATA (Phase 46.5, Plan 46.5-06; 46.5-CONTEXT.md D-07).
 *
 * D-07's warning is the whole reason this class exists: *"If the document
 * generates and the link does not, the PM must be told which half happened …
 * do not report success for a half-run."* A boolean cannot say that. This can.
 *
 * ── IT HOLDS NO MODEL AND FORMATS NO HTML ─────────────────────────────────
 *
 * Three strings from three closed sets, and two questions the controller asks
 * it. No Eloquent model, no id, no URL and no markup. The controller can then
 * SAY what happened instead of guessing from a null.
 *
 * ── SUCCESS IS STRUCTURALLY IMPOSSIBLE FOR A HALF-RUN ─────────────────────
 *
 * `successMessage()` returns NULL whenever `isComplete()` is false. That is
 * not a convention a caller must remember — it is the only way a success
 * sentence can be obtained, so a caller that forgets the check gets nothing to
 * flash rather than a lie. `CockpitCombinedCreationTest::
 * test_no_path_flashes_success_when_the_outcome_is_incomplete()` iterates
 * EVERY constructible state rather than testing one.
 *
 * ── IT NEVER PROMISES BYTES ───────────────────────────────────────────────
 *
 * `BuildWorksheetJob` and `BuildRamsDocumentJob` are QUEUED: the document's
 * BUILD happens after commit and can fail later. So the sentence says the
 * document was created and its build is QUEUED, which is the truth and is the
 * same thing the existing panel already reports. It does not say a file exists.
 */
final class CockpitCreationOutcome
{
    /** The document was created by THIS request. */
    public const DOCUMENT_CREATED = 'created';

    /** A live document existed BEFORE this request and was adopted. */
    public const DOCUMENT_ADOPTED = 'adopted';

    /** No document — either the module has none, or nothing survived. */
    public const DOCUMENT_NONE = 'none';

    public const VISIT_CREATED = 'created';

    public const VISIT_NONE = 'none';

    public const LINK_ISSUED = 'issued';

    public const LINK_NONE = 'none';

    /** @var array<int, string> */
    public const DOCUMENT_STATES = [self::DOCUMENT_CREATED, self::DOCUMENT_ADOPTED, self::DOCUMENT_NONE];

    /** @var array<int, string> */
    public const VISIT_STATES = [self::VISIT_CREATED, self::VISIT_NONE];

    /** @var array<int, string> */
    public const LINK_STATES = [self::LINK_ISSUED, self::LINK_NONE];

    /**
     * @param  string  $document  one of DOCUMENT_STATES
     * @param  string  $visit     one of VISIT_STATES
     * @param  string  $link      one of LINK_STATES
     * @param  bool    $expectsVisit  whether THIS MODULE has a visit and a link
     *                                at all. RAMS and the O&M do not (D-04), so
     *                                "no visit" is COMPLETE for them and a
     *                                FAILURE for the other two. The difference
     *                                is a fact about the module, never a guess
     *                                made from the other three values.
     */
    private function __construct(
        public readonly string $document,
        public readonly string $visit,
        public readonly string $link,
        public readonly bool $expectsVisit,
    ) {
    }

    /** Document + visit + link, all of it. */
    public static function complete(string $document): self
    {
        return new self($document, self::VISIT_CREATED, self::LINK_ISSUED, true);
    }

    /** A module with no visit and no link of its own — RAMS, the O&M (D-04). */
    public static function documentOnly(string $document): self
    {
        return new self($document, self::VISIT_NONE, self::LINK_NONE, false);
    }

    /**
     * NOTHING SURVIVED — or nothing survived EXCEPT a document this request did
     * not create.
     *
     * `$adopted` is the one thing a rollback cannot undo: a survey that existed
     * BEFORE the request is still there afterwards, untouched, because a
     * transaction only rolls back what it wrote. The PM is told exactly that
     * rather than "nothing happened", which would be false.
     */
    public static function rolledBack(bool $adopted = false): self
    {
        return new self(
            $adopted ? self::DOCUMENT_ADOPTED : self::DOCUMENT_NONE,
            self::VISIT_NONE,
            self::LINK_NONE,
            true,
        );
    }

    /**
     * EVERY STATE THIS CLASS CAN BE IN, so a test iterates them rather than
     * sampling one. A failure mode that is never constructed is a failure mode
     * nothing proves anything about.
     *
     * @return array<int, self>
     */
    public static function everyState(): array
    {
        $states = [];

        foreach (self::DOCUMENT_STATES as $document) {
            foreach (self::VISIT_STATES as $visit) {
                foreach (self::LINK_STATES as $link) {
                    foreach ([true, false] as $expectsVisit) {
                        $states[] = new self($document, $visit, $link, $expectsVisit);
                    }
                }
            }
        }

        return $states;
    }

    /**
     * DID ALL OF IT HAPPEN?
     *
     * For a module with a link: the document exists AND the visit was created
     * AND the link was issued. For a module without one: the document exists
     * AND there is neither a visit nor a link — a stray visit on RAMS would be
     * a defect, not a bonus, so it is NOT complete either.
     */
    public function isComplete(): bool
    {
        if ($this->document === self::DOCUMENT_NONE) {
            return false;
        }

        return $this->expectsVisit
            ? $this->visit === self::VISIT_CREATED && $this->link === self::LINK_ISSUED
            : $this->visit === self::VISIT_NONE && $this->link === self::LINK_NONE;
    }

    /**
     * THE ONLY WAY TO OBTAIN A SUCCESS SENTENCE, AND IT IS NULL FOR A HALF-RUN.
     *
     * This is the executable half of D-07's "do not report success for a
     * half-run": there is nothing to flash unless everything happened.
     */
    public function successMessage(): ?string
    {
        if (! $this->isComplete()) {
            return null;
        }

        $document = $this->document === self::DOCUMENT_ADOPTED
            ? 'This project\'s existing survey was used'
            : 'The document was created and its build is queued';

        return $this->expectsVisit
            ? $document.', the visit was created, and the engineer link is ready.'
            : $document.'.';
    }

    /**
     * WHAT HAPPENED, IN WORDS A PM CAN ACT ON — INCLUDING WHICH HALF.
     *
     * Fixed copy, built from the three states. No exception text, no stack
     * trace and no submitted value reaches the PM (T-46.5-06-07); `report($e)`
     * carries the detail to the log where it belongs.
     */
    public function sentence(): string
    {
        if ($this->isComplete()) {
            return (string) $this->successMessage();
        }

        if ($this->document === self::DOCUMENT_ADOPTED) {
            return 'This project\'s existing survey was used and left exactly as it was. '
                .'The visit and the engineer link were NOT created. Nothing else was saved, '
                .'so you can safely try again.';
        }

        return 'Nothing was created. The document, the visit and the engineer link were all '
            .'rolled back, so you can safely try again.';
    }
}
