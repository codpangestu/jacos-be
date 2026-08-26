<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * FR-BE-5.2 — simpan subscription Web Push (VAPID) untuk device/user ini.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['user_id' => $request->user()->id, 'endpoint' => $data['endpoint']],
            ['public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth']]
        );

        return response()->json(['subscription' => $subscription], 201);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['message' => 'Subscription removed.']);
    }
}
