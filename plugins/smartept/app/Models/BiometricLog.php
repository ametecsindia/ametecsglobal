<?php

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BiometricLog extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $casts = [
        'punched_at' => 'datetime',
        'processed'  => 'boolean',
        'metadata'   => 'array',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function device()   { return $this->belongsTo(BiometricDevice::class, 'biometric_device_id'); }

    /**
     * 10-Nov-2026 (Ejaz): an organisation whose Attendance source is "QR Attendance" counts ONLY
     * QR punches. A biometric reader that is still connected must not open Gate-to-PC or mark
     * attendance — Altaf was let into the agent by an eTimeOffice punch with no QR scan.
     * QR punches are the rows App\Qronex\EptHost writes, with external_id "qx:<ref>".
     * Every other attendance mode is untouched.
     */
    public static function qrOnly(int $companyId): bool
    {
        return Company::withoutGlobalScopes()->whereKey($companyId)->value('attendance_mode') === 'QR';
    }

    public static function isQrPunch(?string $externalId): bool
    {
        return str_starts_with((string) $externalId, self::QR_PREFIX);
    }

    public const QR_PREFIX = 'qx:';

    /** Drop the punches that do not count for this company (non-QR punches in QR mode). */
    public static function onlyCounted(int $companyId, array $rows): array
    {
        if (! $rows || ! self::qrOnly($companyId)) {
            return $rows;
        }

        return array_values(array_filter($rows, fn ($r) => self::isQrPunch($r['external_id'] ?? null)));
    }
}
