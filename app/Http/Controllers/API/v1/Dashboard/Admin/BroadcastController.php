<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Http\Controllers\API\v1\Dashboard\Admin\AdminBaseController;
use App\Http\Requests\BroadcastSendRequest;
use App\Services\BroadcastService\BroadcastService;
use Illuminate\Http\JsonResponse;

class BroadcastController extends AdminBaseController
{
    public function __construct(private BroadcastService $service)
    {
        parent::__construct();
    }

    /**
     * Send broadcast message.
     */
    public function send(BroadcastSendRequest $request): JsonResponse
    {
        $stats = $this->service->send($request->validated());

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            $stats
        );
    }
} 