<?php

namespace Ametecs\Qronex\Models;

use Ametecs\Qronex\Models\Concerns\Tenanted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use Tenanted;

    public const SCAN_SCOPES = ['gate' => 'Only the bound gate', 'office' => 'Any gate of the assigned offices', 'any' => 'Any office and gate'];

    protected $table = 'qx_employees';

    protected $guarded = ['id'];

    protected $hidden = ['activation_code_hash'];

    protected function casts(): array
    {
        return ['activation_expires_at' => 'datetime', 'office_ids' => 'array'];
    }

    public function office(): BelongsTo { return $this->belongsTo(Office::class); }
    public function gate(): BelongsTo { return $this->belongsTo(Gate::class); }
    public function devices(): HasMany { return $this->hasMany(Device::class); }
    public function events(): HasMany { return $this->hasMany(AttendanceEvent::class); }

    /** One phone per employee; more only as an exception granted by an admin (configure ability). */
    public function deviceLimit(): int
    {
        return max(1, (int) $this->max_devices);
    }

    /** 08-Nov-2026: every office this employee may punch at — the main office plus any extra ones ticked. */
    public function officeIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter(array_merge([$this->office_id], (array) $this->office_ids)))));
    }

    /** Names of those offices, main office first. */
    public function officeNames(): array
    {
        $ids = $this->officeIds();
        $names = Office::withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id');

        return array_values(array_filter(array_map(fn ($id) => $names[$id] ?? null, $ids)));
    }

    /** Can this employee punch at $gate? null = yes, otherwise the reason. */
    public function scanBlockedAt(Gate $gate): ?string
    {
        $office = ! in_array((int) $gate->office_id, $this->officeIds(), true)
            ? (count($this->officeIds()) > 1 ? 'Assigned offices: '.implode(', ', $this->officeNames()) : 'Assigned office: '.($this->office?->name ?? 'none'))
            : null;

        return match ($this->scan_scope ?: 'office') {
            'any' => null,
            'gate' => $this->gate_id && (int) $this->gate_id !== (int) $gate->id ? 'Bound to gate '.($this->gate?->name ?? '#'.$this->gate_id).' only' : $office,
            default => $office,
        };
    }

    /** Inactive + every phone revoked (the app is signed out). */
    public function deactivate(?int $byUserId = null, string $reason = 'Employee deactivated'): int
    {
        $this->update(['status' => 'inactive']);
        $devices = Device::withoutGlobalScopes()->where('employee_id', $this->id)->where('status', '!=', 'revoked')->get();
        foreach ($devices as $d) {
            $d->update(['status' => 'revoked', 'token_hash' => null, 'approval_code' => null]);
            $d->log('revoked', $byUserId, $reason);
        }

        return $devices->count();
    }
}
