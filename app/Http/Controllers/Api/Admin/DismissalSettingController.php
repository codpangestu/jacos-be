<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DismissalSetting;
use Illuminate\Http\Request;

class DismissalSettingController extends Controller
{
    public function show()
    {
        return response()->json(['setting' => DismissalSetting::firstOrCreate([])]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'cutoff_time' => ['required', 'date_format:H:i'],
            'attendance_edit_tolerance_days' => ['required', 'integer', 'min:0', 'max:30'],
        ]);

        $setting = DismissalSetting::firstOrCreate([]);
        $setting->update($data);

        return response()->json(['setting' => $setting]);
    }
}
