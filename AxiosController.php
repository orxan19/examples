<?php

namespace App\Http\Controllers;

use App\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AxiosController extends Controller
{
    public function campaigns(Request $request)
    {
        $user = Auth::user(); // Get current user
        $campaigns = Campaign::where('discount_status', 0)->where('user_id', $user->id)
            ->where('id', '<', $request->id)
            ->where('condition_status', 0)
            ->where('complete_status', 0) // Some condition stuff
            ->limit(10)->with('images')->orderBy('id', 'desc')->get(); // Get user products


        foreach ($campaigns as $campaign) {
            $campaign->product_name_limited = Str::limit($campaign->product_name, 70); // Cut product name
            if (empty($campaign->images[0])) {

            } else {
                $campaign->images[0]->filename_formatted = Storage::url($campaign->images[0]->filename);
            }

            if ($campaign->schedules->sum('product_count') == 0) {
                if ($campaign->start_at) {
                    $campaign->start_at_formatted = $campaign->start_at->format('d M.Y'); // Format date for frontend
                }
            } else {
                if ($campaign->start_at) {
                    $campaign->start_at_formatted = $campaign->start_at->format('d M.Y');
                    $campaign->end_at_formatted = $campaign->start_at->addDay(count($campaign->schedules) - 1)
                        ->format('d M.Y');
                }

                $campaign->schedule_count = count($campaign->schedules); // Get how many days product sell

                if (count($campaign->schedules) == 1) {
                    $campaign->day_word = 'day';
                } else {
                    $campaign->day_word = 'days';
                }

                $campaign->product_count = $campaign->schedules->sum('product_count');
            }

        }

        return json_encode($campaigns->toArray()); // Return results in json
    }


}
