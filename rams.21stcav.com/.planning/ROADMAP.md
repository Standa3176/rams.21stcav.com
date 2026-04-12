
### Phase 7: Dynamic Site Survey — AI-generated room questions from solution type, hardware list, and scope

**Goal:** Generate tailored pre-install check questions per survey room at survey creation time, driven by solution type, equipment list, and scope summaries. Engineers answer all questions before marking a room complete.
**Requirements**: D-01 through D-14 (see 07-CONTEXT.md)
**Depends on:** Phase 5 (content pack fields: works_overview, room description/summary)
**Plans:** 6 plans

Plans:
- [ ] 07-01-PLAN.md — Wave 0: Test scaffolds (failing stubs for model, prompt, job, answer endpoint, completion gate)
- [ ] 07-02-PLAN.md — Wave 1: Migration, SiteSurveyRoomQuestion model, SiteSurveyRoom.questions() relationship, SurveyQuestionsPrompt
- [ ] 07-03-PLAN.md — Wave 2: GenerateSurveyQuestionsJob + SurveyService dispatch wiring
- [ ] 07-04-PLAN.md — Wave 3: Answer persistence routes (public token-gated + internal auth-gated)
- [ ] 07-05-PLAN.md — Wave 4a: Pre-Install Checks panel UI in both survey forms + eager load fix
- [ ] 07-06-PLAN.md — Wave 4b: completeRoom() completion gate (422 on unanswered) + JS handler
