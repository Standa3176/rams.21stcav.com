<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 261011-lvf — the survey engineer link's per-room debounced autosave was dead
 * on the live site. Every per-room watch was registered with a path of the
 * shape rooms.${idx}._ui.${f}, which interpolates to rooms.0._ui.work_type.
 * A numeric property straight after a dot is a JavaScript SyntaxError, so
 * Alpine threw "Uncaught SyntaxError: Unexpected number" 81 times on load and
 * not one of those watches ever fired in production. The fix is bracket
 * notation: rooms[${idx}]._ui.${f}.
 *
 * WHAT THIS TEST CAN AND CANNOT PROVE
 *
 * It CANNOT prove the autosave fires. PHPUnit has no Alpine, no layout engine
 * and no JavaScript runtime at all; nothing here executes a watch, a 600 ms
 * debounce or a step-save fetch. All that is asserted below is the SOURCE
 * CONTRACT — that every watch path this view ships is a syntactically valid
 * JavaScript member expression once an index is substituted in. That the save
 * actually fires while typing was measured separately in real headless Chrome
 * (puppeteer), and that measurement, not this file, is the proof the defect is
 * fixed. See this quick task's SUMMARY.
 *
 * The assertions are deliberately GENERAL rather than literal: a future
 * rooms.${i} or rooms.0 added anywhere in this view fails them too, not just
 * the three paths repaired today.
 *
 * WHICH VIEW THIS IS — the same two-file trap the sibling 46.6 tests guard:
 * GET survey/{token} resolves to SurveyController show, rendering
 * resources/views/surveys/show.blade.php. The dead
 * resources/views/public-survey/show.blade.php has no GET route and is never
 * touched here.
 */
class SurveyAutosaveWatchPathsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Interpolated variable names that carry a NUMERIC value, and so must be
     * reached with brackets. Kept as a list rather than a single literal so
     * the check stays general across future renames.
     */
    private const NUMERIC_INDEX_VARS = ['idx', 'i', 'j', 'k', 'n', 'index', 'roomIndex', 'ri', 'roomIdx'];

    private function rawSource(): string
    {
        return file_get_contents(resource_path('views/surveys/show.blade.php'));
    }

    private function renderedPage(): string
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Autosave Watch Paths Fixture',
            'site_address' => '1 Watch Path Way, Leeds',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Autosave Watch Paths Fixture',
            'site_address' => '1 Watch Path Way, Leeds',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (['Board Room', 'Huddle 1'] as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        return $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();
    }

    /**
     * Every string literal handed to a watch registration as its first
     * argument, however quoted (backtick, single or double).
     *
     * @return array<int, string>
     */
    private function watchExpressions(string $haystack): array
    {
        preg_match_all(
            '/\$watch\(\s*(?:`([^`]*)`|\'([^\']*)\'|"([^"]*)")/',
            $haystack,
            $matches,
            PREG_SET_ORDER
        );

        $found = [];
        foreach ($matches as $match) {
            $candidates = [$match[1] ?? '', $match[2] ?? '', $match[3] ?? ''];
            foreach ($candidates as $candidate) {
                if ($candidate !== '') {
                    $found[] = $candidate;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Substitute a realistic value for every interpolation in a watch path:
     * a numeric index variable becomes 0, anything else becomes a field name.
     * This is what Alpine actually ends up evaluating.
     */
    private function interpolate(string $expression): string
    {
        return preg_replace_callback('/\$\{([^}]*)\}/', function (array $m): string {
            $inner = trim($m[1]);

            return in_array($inner, self::NUMERIC_INDEX_VARS, true) ? '0' : 'sampleField';
        }, $expression);
    }

    /** A valid JS member path: identifiers joined by dots, numbers only in brackets. */
    private function isValidJsMemberPath(string $path): bool
    {
        return (bool) preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*|\[\d+\])*$/', $path);
    }

    // -- 1. NON-VACUITY ------------------------------------------------------

    public function test_the_view_really_does_register_watches_including_per_room_ones(): void
    {
        $source      = $this->rawSource();
        $expressions = $this->watchExpressions($source);

        $this->assertGreaterThanOrEqual(
            4,
            count($expressions),
            'Found almost no watch registrations in surveys/show.blade.php — the extraction regex '
            .'has stopped matching, so every assertion below would pass vacuously.'
        );

        $perRoom = array_filter($expressions, fn (string $e): bool => str_starts_with($e, 'rooms'));

        $this->assertGreaterThanOrEqual(
            3,
            count($perRoom),
            'The three per-room autosave watches (steps 1, 2 and the deep step-4 '
            .'engineer_feedback watch) must still be registered.'
        );
    }

    public function test_the_three_per_room_watches_still_drive_their_debounced_autosave(): void
    {
        $source = $this->rawSource();

        $this->assertStringContainsString('this.debouncedAutosave(1)', $source);
        $this->assertStringContainsString('this.debouncedAutosave(2)', $source);
        $this->assertStringContainsString('this.debouncedAutosave(4)', $source);
        $this->assertStringContainsString(
            '{ deep: true }',
            $source,
            'The step-4 engineer_feedback watch must stay deep.'
        );
    }

    // -- 2. THE GENERAL CONTRACT ---------------------------------------------

    public function test_every_watch_path_is_valid_javascript_once_an_index_is_substituted(): void
    {
        $broken = [];

        foreach ($this->watchExpressions($this->rawSource()) as $expression) {
            $evaluated = $this->interpolate($expression);

            if (! $this->isValidJsMemberPath($evaluated)) {
                $broken[] = $expression.'  ->  '.$evaluated;
            }
        }

        $this->assertSame(
            [],
            $broken,
            "A watch path in surveys/show.blade.php is not valid JavaScript once its index is\n"
            ."substituted. A numeric property after a dot throws SyntaxError: Unexpected number and\n"
            ."Alpine never registers the watch, so the autosave it drives is dead. Use brackets.\n"
            .'Offending: '.implode('; ', $broken)
        );
    }

    public function test_no_watch_path_puts_an_index_straight_after_a_dot(): void
    {
        $numeric   = implode('|', self::NUMERIC_INDEX_VARS);
        $offenders = [];

        foreach ($this->watchExpressions($this->rawSource()) as $expression) {
            if (preg_match('/\.\d/', $expression)
                || preg_match('/\.\$\{\s*(?:'.$numeric.')\s*\}/', $expression)) {
                $offenders[] = $expression;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A watch path reaches a numeric index with dot notation. That is a JavaScript syntax '
            .'error, not a style preference. Offending: '.implode('; ', $offenders)
        );
    }

    public function test_the_repaired_paths_use_bracket_notation(): void
    {
        $source = $this->rawSource();

        $this->assertSame(
            2,
            substr_count($source, 'rooms[${idx}]._ui.${f}'),
            'Both the step-1 and step-2 per-room _ui watches must use bracket notation.'
        );

        $this->assertStringContainsString(
            'rooms[${idx}].engineer_feedback',
            $source,
            'The deep per-room engineer_feedback watch must use bracket notation.'
        );
    }

    // -- 3. THE VALID SIBLING MUST BE LEFT ALONE -----------------------------

    public function test_the_site_level_engineer_feedback_watch_is_untouched(): void
    {
        $source = $this->rawSource();

        $this->assertSame(
            1,
            substr_count($source, '$watch(`engineerFeedbackSite.${f}`'),
            'The site-level watch carries no numeric index, was always valid, and must stay '
            .'exactly as it was — it is the contrast that proved the diagnosis.'
        );

        $this->assertStringContainsString('this.debouncedAutosaveSite()', $source);
    }

    // -- 4. WHAT ACTUALLY SHIPS TO THE BROWSER -------------------------------

    public function test_the_rendered_page_ships_only_valid_watch_paths(): void
    {
        $html        = $this->renderedPage();
        $expressions = $this->watchExpressions($html);

        $this->assertGreaterThanOrEqual(
            4,
            count($expressions),
            'The rendered engineer link carries no watch registrations — this assertion would be vacuous.'
        );

        $broken = [];
        foreach ($expressions as $expression) {
            if (! $this->isValidJsMemberPath($this->interpolate($expression))) {
                $broken[] = $expression;
            }
        }

        $this->assertSame(
            [],
            $broken,
            'The page actually served to an engineer still ships an invalid watch path: '
            .implode('; ', $broken)
        );
    }
}
