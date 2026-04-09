<?php

namespace App\Modules\NewsRadar\Models;

use App\Modules\NewsRadar\Enums\DiscoveryRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SourceDiscoveryRun extends Model
{
    use HasUuids;

    protected $fillable = ['url', 'status', 'result', 'error_message'];

    protected function casts(): array
    {
        return [
            'status' => DiscoveryRunStatus::class,
            'result' => 'array',
        ];
    }
}
