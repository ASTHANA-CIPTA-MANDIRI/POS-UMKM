<?php

namespace App\Models\Tenant;

use App\Enums\DeviceEventType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['device_id', 'event_type', 'oleh_user_id', 'catatan', 'lat_saat_event', 'lng_saat_event'])]
class DeviceAssetLog extends Model
{
    use BelongsToTenant, HasUuids;

    protected function casts(): array
    {
        return [
            'event_type' => DeviceEventType::class,
            'lat_saat_event' => 'decimal:7',
            'lng_saat_event' => 'decimal:7',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function olehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'oleh_user_id');
    }
}
