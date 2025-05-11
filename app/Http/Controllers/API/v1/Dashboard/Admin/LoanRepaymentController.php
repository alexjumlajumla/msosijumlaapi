<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Models\LoanRepayment;
use App\Services\LoanService\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanRepaymentController extends AdminBaseController
{
    public function __construct(private LoanService $service)
    {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $repayments = LoanRepayment::with(['loan','user'])
            ->orderBy($request->input('column','id'), $request->input('sort','desc'))
            ->paginate($request->input('perPage',15));

        return $this->successResponse(__('web.list_of_records_found'), $repayments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'loan_id'        => 'required|exists:loans,id',
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'required|in:wallet,mobile_money,cash,card',
        ]);

        $result = $this->service->recordRepayment($data);
        return $result['status']
            ? $this->successResponse(__('web.record_was_successfully_created'), $result['data'])
            : $this->onErrorResponse($result);
    }

    public function destroy(LoanRepayment $repayment): JsonResponse
    {
        $repayment->delete();
        return $this->successResponse(__('web.record_was_successfully_deleted'));
    }
} 