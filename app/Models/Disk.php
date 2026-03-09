<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Disk extends Model
{
    use HasFactory;

    public $table = 'server_disks';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'server_id', 'disk_size', 'disk_unit', 'disk_as_gb', 'disk_media'];

    public static function insertDisk(string $server_id, int $size, string $unit, string $media): Disk
    {
        $disk_as_gb = ($unit === 'TB') ? ($size * 1024) : $size;

        return self::create([
            'id' => Str::random(8),
            'server_id' => $server_id,
            'disk_size' => $size,
            'disk_unit' => $unit,
            'disk_as_gb' => $disk_as_gb,
            'disk_media' => $media,
        ]);
    }

    public static function deleteDisksForServer(string $server_id): void
    {
        DB::table('server_disks')->where('server_id', $server_id)->delete();
    }
}
