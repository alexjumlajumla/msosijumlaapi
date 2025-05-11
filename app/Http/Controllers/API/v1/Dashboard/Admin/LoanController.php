<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Http\Controllers\API\v1\Dashboard\Admin\AdminBaseController;
use App\Helpers\ResponseError;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\LoanService\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoanController extends AdminBaseController
{
    public function __construct(private LoanService $service)
    {
        parent::__construct();
    }

    /**
     * Display a listing of loans
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $loans = Loan::with(['vendor', 'repayments'])
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->orderBy($request->input('column', 'id'), $request->input('sort', 'desc'))
            ->paginate($request->input('perPage', 15));

        return LoanResource::collection($loans);
    }

    /**
     * Disburse a new loan
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'       => 'required|exists:users,id',
            'amount'        => 'required|numeric|min:1',
            'interest_rate' => 'required|numeric|min:0',
            'due_date'      => 'required|date|after:today',
            'note'          => 'nullable|string',
        ]);

        $result = $this->service->disburseLoan($data);

        return $result['status']
            ? $this->successResponse(__('web.record_was_successfully_created'), $result['data'])
            : $this->onErrorResponse($result);
    }

    /**
     * Display loan details
     */
    public function show(Loan $loan): JsonResponse
    {
        return $this->successResponse(__('web.record_has_been_successfully_found'), $loan->load(['vendor','repayments']));
    }

    /**
     * Record a loan repayment
     */
    public function recordRepayment(Request $request): JsonResponse
    {
        $result = $this->service->recordRepayment($request->all());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
            data_get($result, 'data')
        );
    }

    /**
     * Get loan statistics for a user
     */
    public function userStats(int $userId): JsonResponse
    {
        $stats = $this->service->getUserLoanStats($userId);

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            $stats
        );
    }

    // DELETE dashboard/admin/loans/{loan}
    public function destroy(Loan $loan): JsonResponse
    {
        $loan->delete();
        return $this->successResponse(__('web.record_was_successfully_deleted'));
    }
}
