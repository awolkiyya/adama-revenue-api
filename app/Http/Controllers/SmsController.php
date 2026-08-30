<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\SmsService;

class SmsController extends Controller
{
    public function __construct(private SmsService $smsService) {}

    public function sendPhone(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
        ]);

        $result = $this->smsService->sendByPhone(
            $request->phone,
            $request->message
        );

        return response()->json($result);
    }

    public function sendOtp(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
        ]);

        $result = $this->smsService->sendOtp(
            $request->phone
        );

        return response()->json($result);
    }

    public function sendBulk(Request $request)
    {
        $request->validate([
            'phones' => 'required|array',
            'phones.*' => 'string',
            'message' => 'required|string',
        ]);

        $result = $this->smsService->sendBulk(
            $request->phones,
            $request->message
        );

        return response()->json($result);
    }
}