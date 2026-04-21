<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for retro-edits to a finished TimeEntry.
 *
 * Phase 15 only ever writes audit rows via TimeEntryService::editEntry()
 * (Plan 15-02). No update()/delete() code paths ship — retcon detection
 * for ops depends on the permanence of these rows. FK restrictOnDelete on
 * edited_by_user_id preserves history even if the editing user is later
 * removed.
 *
 * Current retro-editable fields (D-04, D-07):
 *   - 'category' — change a past entry's category
 *   - 'notes'    — change or add a past entry's note
 *
 * Extending FIELDS is a deliberate schema decision, not a drop-in —
 * downstream consumers (Plan 15-04 history UI) enumerate this constant.
 *
 * @see TimeEntry::audits() — reverse relation
 * @see User::timeEntryAudits() — "edits I made"
 */
class TimeEntryAudit extends Model
{
    use HasFactory;

    public const FIELD_CATEGORY = 'category';
    public const FIELD_NOTES    = 'notes';

    public const FIELDS = [
        self::FIELD_CATEGORY,
        self::FIELD_NOTES,
    ];

    protected $fillable = [
        'time_entry_id',
        'edited_by_user_id',
        'field',
        'old_value',
        'new_value',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_user_id');
    }
}
