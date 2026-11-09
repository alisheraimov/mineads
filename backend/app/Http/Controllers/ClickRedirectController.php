<?php

namespace App\Http\Controllers;

use App\Models\AdDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClickRedirectController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token), 404);
        $delivery = AdDelivery::query()->where('click_token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($delivery->decision === 'served' && $delivery->click_destination, 404);
        abort_if(! $delivery->click_expires_at || $delivery->click_expires_at->lessThanOrEqualTo(now()), 410, 'This ad link has expired.');
        abort_unless(in_array(strtolower(parse_url($delivery->click_destination, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true), 404);

        if ($request->isMethod('GET')) {
            $visitor = hash_hmac('sha256', json_encode([$request->ip(), $request->userAgent()]), config('app.key'));
            $automated = ! $request->userAgent()
                || preg_match('/bot|crawler|spider|preview|slurp|facebookexternalhit/i', $request->userAgent())
                || str_contains(strtolower($request->header('Purpose', '').' '.$request->header('Sec-Purpose', '')), 'prefetch');

            DB::transaction(function () use ($delivery, $visitor, $automated): void {
                AdDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                $duplicate = DB::table('raw_click_events')->where('ad_delivery_id', $delivery->id)
                    ->where('visitor_hash', $visitor)->where('is_unique', true)->exists();
                DB::table('raw_click_events')->insert([
                    'ad_delivery_id' => $delivery->id,
                    'visitor_hash' => $visitor,
                    'is_unique' => ! $automated && ! $duplicate,
                    'exclusion_reason' => $automated ? 'automated' : ($duplicate ? 'duplicate' : null),
                    'received_at' => now(),
                ]);
            }, 3);
        }

        return redirect()->away($delivery->click_destination, 302, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
