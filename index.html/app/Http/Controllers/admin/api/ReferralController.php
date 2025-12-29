<?php

namespace App\Http\Controllers\admin\api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReferralRequest;
use App\Models\ReferralLevel;
use Illuminate\Http\JsonResponse;

class ReferralController extends Controller
{
    /**
     * List all rebates.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $referrals = ReferralLevel::all();
        return response()->json([
            'success' => true,
            'message' => 'Rebate index method called',
            'data' => $referrals
        ]);
    }

    /**
     * Store a new rebate.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(CreateReferralRequest $request): JsonResponse
    {
        $referral = ReferralLevel::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Rebate created successfully',
            'data' => $referral
        ]);
    }

    /**
     * Store a new rebate.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(ReferralLevel $referralLevel): JsonResponse
    {
        $referralLevel->delete();

        return response()->json([
            'success' => true,
            'message' => 'Referral Level deleted successfully',
            'data' => null
        ]);
    }
}
