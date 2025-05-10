<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use App\Services\LoanServices\LoanService;
use App\Models\Loan;
use App\Models\User;
use App\Models\CreditScore;
use Illuminate\Pagination\LengthAwarePaginator;



class LoanController extends Controller
{
    protected $loanService;

    public function __construct(LoanService $loanService)
    {
        $this->loanService = $loanService;
    }

    /**
     * Disburse a loan
     */

    public function disburse(Request $request)
    {
        try {
            $data = $request->validate([
                'user_id'       => 'required|exists:users,id',
                'amount'        => 'required|numeric|min:1',
                'interest_rate' => 'required|numeric|min:0',
                'due_date'      => 'required|date|after:today',
            ]);

            $adminId = auth('sanctum')->id();
            $loan = $this->loanService->disburseLoan($data, $adminId);

            return response()->json([
                'status'  => true,
                'message' => 'Loan disbursed successfully.',
                'data'    => [
                    'id'               => $loan->id,
                    'user_id'          => $loan->user_id,
                    'amount'           => $loan->amount,
                    'interest_rate'    => $loan->interest_rate,
                    'repayment_amount' => $loan->repayment_amount,
                    'status'           => $loan->status,
                    'disbursed_at'     => $loan->disbursed_at,
                    'due_date'         => $loan->due_date,
                ]
            ], 201);
        } catch (ValidationException $e) {
            return $this->loanService->handleValidationException($e);
        } catch (\Throwable $e) {
            return $this->loanService->handleGeneralException($e, 'An error occurred during loan disbursement.');
        }
    }

    /**
     * Record manual repayment
     */
    public function recordRepayment(Request $request)
    {
        try {
            $data = $request->validate([
                'loan_id'        => 'required|exists:loans,id',
                'amount'         => 'required|numeric|min:1',
                'paid_at'        => 'nullable|date',
                'payment_method' => 'required|in:cash,wallet,mobile_money,card',
            ]);

            $adminId = auth('sanctum')->id();
            $repayment = $this->loanService->recordRepayment($data, $adminId);

            return response()->json([
                'status'  => true,
                'message' => 'Repayment recorded successfully.',
                'data'    => $repayment
            ], 201);
        } catch (ValidationException $e) {
            return $this->loanService->handleValidationException($e);
        } catch (\Throwable $e) {
            return $this->loanService->handleGeneralException($e, 'An error occurred while recording the repayment.');
        }
    }

    /**
     * List loans with details
     */
    public function index(Request $request)
    {
        $loans = Loan::with(['vendor', 'repayments'])->paginate(20);
    
        // Get the current page items (collection)
        $formattedLoans = $loans->items();
    
        // Modify the collection
        $formattedLoans = collect($formattedLoans)->map(function ($loan) {
            return [
                'id'               => $loan->id,
                'user_id'          => $loan->user_id,
                'amount'           => $loan->amount,
                'interest_rate'    => $loan->interest_rate,
                'repayment_amount' => $loan->repayment_amount,
                'disbursed_by'     => $loan->disbursed_by,
                'disbursed_at'     => $loan->disbursed_at,
                'due_date'         => $loan->due_date,
                'status'           => $loan->status,
                'created_at'       => $loan->created_at,
                'updated_at'       => $loan->updated_at,
                'vendor' => $loan->vendor ? [
                    'id'     => $loan->vendor->id,
                    'name'   => $loan->vendor->firstname . ' ' . $loan->vendor->lastname,
                    'email'  => $loan->vendor->email,
                    'phone'  => $loan->vendor->phone,
                    'gender' => $loan->vendor->gender,
                ] : null,
                'repayments' => $loan->repayments->map(function ($repayment) {
                    return [
                        'id'             => $repayment->id,
                        'amount'         => $repayment->amount,
                        'payment_method' => $repayment->payment_method,
                        'paid_at'        => $repayment->paid_at,
                    ];
                }),
            ];
        });
    
        // Create a new paginator with the modified collection
        $paginatedLoans = new LengthAwarePaginator(
            $formattedLoans,  // The modified collection
            $loans->total(),  // Total number of items (usually from the query)
            $loans->perPage(), // Number of items per page
            $loans->currentPage(), // Current page number
            ['path' => url()->current()] // The current URL (for pagination links)
        );
    
        return response()->json([
            'status' => true,
            'data'   => $paginatedLoans,
        ]);
    }
    

    /**
     * Get a user's total outstanding loan balance
     */

    public function getUserLoanBalance($userId)
{
    // Validate user_id exists
    $user = User::find($userId);

    if (!$user) {
        return response()->json([
            'status' => false,
            'message' => 'User not found.',
        ], 404);
    }

    $response = $this->loanService->getUserLoanBalance(['user_id' => $userId]);

    return response()->json($response);
}



    public function repayFromWallet(Request $request)
{
    $data = $request->validate([
        'loan_id' => 'required|exists:loans,id',
    ]);

    $adminId = auth('sanctum')->id();

    try {
        $repayment = $this->loanService->repayLoanFromWallet($data['loan_id'], $adminId);

        return response()->json([
            'status'  => true,
            'message' => 'Loan repayment from wallet successful.',
            'data'    => $repayment
        ], 200);
    } catch (ValidationException $e) {
        return $this->loanService->handleValidationException($e);
    } catch (\Throwable $e) {
        return $this->loanService->handleGeneralException($e, 'Failed to repay loan from wallet.');
    }

    
}




public function getUserCreditScore($userId)
{
    try {
        // Validate user_id
        if (!User::where('id', $userId)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or non-existent user_id.',
            ], 422);
        }

        // Fetch the credit score along with the user's email
        $userCreditScore = CreditScore::where('user_id', $userId)
            ->with('user')  // Ensure this relationship exists
            ->first();

        if (!$userCreditScore) {
            return response()->json([
                'status'  => false,
                'message' => 'Credit score not found for the user.',
            ], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => 'User credit score retrieved successfully.',
            'data'    => [
                'user_id'      => $userCreditScore->user_id,
                'email'        => $userCreditScore->user->email,
                'credit_score' => $userCreditScore->current_score,
                'score_date'   => $userCreditScore->created_at,
            ]
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status'  => false,
            'message' => 'An error occurred while fetching the credit score.',
            'error'   => $e->getMessage(),
        ], 500);
    }
}




}
