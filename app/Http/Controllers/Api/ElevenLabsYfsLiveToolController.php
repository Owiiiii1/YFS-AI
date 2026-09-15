<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Voice\Tools\GetPublicShowsVoiceTool;
use App\Services\Voice\Tools\GetShowBrandsVoiceTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ElevenLabsYfsLiveToolController extends Controller
{
    public function publicShows(Request $request, GetPublicShowsVoiceTool $tool): JsonResponse
    {
        return response()->json($tool->execute($this->arguments($request)));
    }

    public function showBrands(Request $request, GetShowBrandsVoiceTool $tool): JsonResponse
    {
        return response()->json($tool->execute($this->arguments($request)));
    }

    /**
     * @return array{show_name: string, city: string}
     */
    private function arguments(Request $request): array
    {
        $showName = $request->input('show_name', $request->input('arguments.show_name'));
        $city = $request->input('city', $request->input('arguments.city'));

        return [
            'show_name' => is_string($showName) ? $showName : '',
            'city' => is_string($city) ? $city : '',
        ];
    }
}
