<?php

namespace App\Traits;

use App\Helpers\ResponseError;
use App\Models\Order;
use App\Models\PushNotification;
use App\Models\Settings;
use App\Models\User;
use App\Services\PushNotificationService\PushNotificationService;
use Cache;
use Google\Client;
use Illuminate\Support\Facades\Http;
use Log;
use Illuminate\Support\Facades\DB;

/**
 * App\Traits\Notification
 *
 * @property string $language
 */
trait Notification
{
	public function sendNotification1(
		array   $receivers = [],
		?string $message = '',
		?string $title = null,
		mixed   $data = [],
		array   $userIds = [],
		?string $firebaseTitle = '',
	): void
	{
		// Convert the old 5-/6-arg signature into the new payload shape
		$payload = is_array($data) ? $data : [];
		$payload['title'] = $firebaseTitle ?: ($title ?? '');
		$payload['body']  = $message;

		// Delegate to the modern implementation that already
		// (1) logs nicely, (2) cleans tokens, (3) downgrades log level
		$this->sendNotificationSimple(
			$this->normalizeTokens($receivers),
			$payload,
			$userIds
		);
	}

	/**
	 * Flexible public method that supports both the legacy (<= Laravel v10) 5/6-parameter
	 * signature and the newer 3-parameter signature introduced later on.  
	 *   Legacy:   sendNotification(array $tokens, string $message, string|null $title, mixed $data, array $userIds = [], ?string $firebaseTitle = '')
	 *   Current:  sendNotification(array $tokens, mixed $dataArray, array $userIds = [])
	 *
	 * Any call with more than 3 arguments is automatically forwarded to the legacy
	 * implementation (sendNotification1). Otherwise it is handled by the streamlined
	 * implementation that expects the newer 3-argument form.
	 *
	 * This wrapper helps prevent "Too many arguments" fatal errors and guarantees
	 * backward compatibility so that older service classes (OrderService, etc.) keep
	 * working without immediate refactoring.
	 */
	public function sendNotification(...$args): void
	{
		// If more than 3 arguments, assume legacy call signature.
		if (count($args) > 3) {
			// Legacy method already contains all required logic.
			$this->sendNotification1(...$args);
			return;
		}

		// Modern (new) signature expects exactly 1-3 arguments.
		[$receivers, $data, $userIds] = $args + [0 => [], 1 => [], 2 => []];

		$this->sendNotificationSimple(
			is_array($receivers) ? $receivers : [$receivers],
			$data,
			is_array($userIds) ? $userIds : [$userIds]
		);
	}

	/**
	 * New streamlined implementation kept under a separate name so that the public
	 * sendNotification() wrapper can smartly delegate between versions.
	 *
	 * DO NOT call this method directly outside this trait – use sendNotification()
	 * to ensure backward compatibility.
	 */
	public function sendNotificationSimple(
		array   $receivers = [],
		mixed   $data = [],
		array   $userIds = []
	): void
	{
		dispatch(function () use ($receivers, $data, $userIds) {
			if (empty($receivers)) {
				\Log::error('[PushService] No receivers provided for notification');
				return;
			}

			\Log::info('[PushService] Preparing notification for ' . count($receivers) . ' receivers', [
				'title'   => is_array($data) ? $data['title'] ?? '' : '',
				'body'    => is_array($data) ? $data['body']  ?? '' : $data,
				'userIds' => $userIds
			]);

			// Store notification in database if userIds are provided
			if (!empty($userIds) && is_array($userIds)) {
				try {
					foreach ($userIds as $userId) {
						PushNotification::create([
							'user_id' => $userId,
							'type'    => is_array($data) ? ($data['type'] ?? 'system') : 'system',
							'title'   => is_array($data) ? ($data['title'] ?? '')          : '',
							'body'    => is_array($data) ? ($data['body']  ?? '')          : (string)$data,
							'data'    => $data,
						]);
					}
				} catch (\Exception $e) {
					\Log::error('[PushService] Failed to store notification: ' . $e->getMessage());
				}
			}

			// Get Firebase auth token
			try {
				$authToken = $this->updateToken();
				if (empty($authToken)) {
					\Log::error('[PushService] Failed to get Firebase auth token');
					return;
				}
			} catch (\Exception $e) {
				\Log::error('[PushService] Error getting Firebase auth token: ' . $e->getMessage());
				return;
			}

			// Ensure tokens are individual strings
			$processedReceivers = [];
			foreach ($receivers as $receiver) {
				if (is_array($receiver)) {
					// Nested arrays – flatten them
					foreach ($receiver as $tokenItem) {
						if (!empty($tokenItem) && is_string($tokenItem)) {
							$processedReceivers[] = $tokenItem;
						}
					}
					continue;
				}

				if (is_string($receiver) && !empty($receiver)) {
					$trimmed = trim($receiver);

					// If token looks like a JSON array (e.g. "[\"tok1\",\"tok2\"]") decode it
					if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
						$decoded = json_decode($trimmed, true);
						if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
							foreach ($decoded as $tok) {
								if (!empty($tok) && is_string($tok)) {
									$processedReceivers[] = $tok;
								}
							}
							continue;
						}
					}

					// If token contains comma-separated list, split it
					if (str_contains($trimmed, ',')) {
						foreach (explode(',', $trimmed) as $tok) {
							$tok = trim($tok, " \"'[]");
							if (!empty($tok)) {
								$processedReceivers[] = $tok;
							}
						}
						continue;
					}

					// Fallback – assume the string itself is a single token
					$processedReceivers[] = $trimmed;
				}
			}

			if (empty($processedReceivers)) {
				\Log::error('[PushService] No valid receivers after processing');
				return;
			}

			$successCount = 0;
			$failureCount = 0;

			// Build base notification payload pieces once
			$notificationBody  = is_array($data) ? ($data['body']  ?? '') : (string)$data;
			$notificationTitle = is_array($data) ? ($data['title'] ?? '') : '';

			// Send to each token individually
			foreach ($processedReceivers as $token) {
				try {
					// Ensure data values are strings for FCM
					$formattedData = [];
					if (is_array($data)) {
						foreach ($data as $key => $value) {
							if (in_array($key, ['title', 'body'], true)) {
								continue; // Skip title/body keys
							}
							$formattedData[$key] = is_scalar($value) ? (string)$value : json_encode($value);
						}
					} else {
						$formattedData['message'] = (string)$data;
					}

					$payload = [
						'message' => [
							'token'        => $token,
							'notification' => [
								'title' => $notificationTitle,
								'body'  => $notificationBody,
							],
							'data' => $formattedData,
							'android' => [
								'priority'      => 'high',
								'notification'  => [
									'sound'      => 'default',
									'channel_id' => 'high_importance_channel'
								]
							],
							'apns' => [
								'headers' => [
									'apns-priority' => '10'
								],
								'payload' => [
									'aps' => [
										'sound'             => 'default',
										'badge'             => 1,
										'content-available' => 1
									]
								]
							]
						]
					];

					$response = \Illuminate\Support\Facades\Http::withHeaders([
							'Authorization' => 'Bearer ' . $authToken,
							'Content-Type'  => 'application/json',
						])
						->timeout(10)
						->post('https://fcm.googleapis.com/v1/projects/' . $this->projectId() . '/messages:send', $payload);

					if ($response->successful()) {
						$successCount++;
					} else {
						$failureCount++;

						// Handle specific FCM errors so we can clean up invalid tokens
						$isKnownBadTokenError = false;
						$errorData = $response->json();
						$errorMessage = $errorData['error']['message'] ?? null;
						
						if ($errorMessage && (
								str_contains($errorMessage, 'InvalidRegistration') ||
								str_contains($errorMessage, 'NotRegistered') ||
								str_contains($errorMessage, 'MismatchSenderId')
							)) {
							$isKnownBadTokenError = true;
							\Log::warning('[PushService] Invalid token detected – removing from user profile', [
								'token' => substr($token, 0, 15) . '...',
								'error' => $errorMessage,
							]);

							try {
								$user = \App\Models\User::where('firebase_token', $token)->orWhereJsonContains('firebase_token', $token)->first();
								if ($user) {
									// Handle both single token string and array of tokens
									$currentToken = $user->firebase_token;
									$newToken = null;
									if (is_array($currentToken)) {
										$newToken = array_values(array_filter($currentToken, fn($t) => $t !== $token));
										if (empty($newToken)) {
											$newToken = null; // Set to null if array becomes empty
										}
									} // else: if it was a single string, setting to null is correct
									
									$user->update(['firebase_token' => $newToken]);
									\Log::info("[PushService] Removed invalid token from user #{$user->id}");
								}
							} catch (\Throwable $e) {
								\Log::error('[PushService] Failed to remove invalid token: ' . $e->getMessage());
							}
						}

						// Log as ERROR only if it's not a known bad token error being handled
						if (!$isKnownBadTokenError) {
							\Log::error('[PushService] Failed to send notification', [
								'status'   => $response->status(),
								'response' => $errorData, // Use cached JSON data
								'token'    => substr($token, 0, 15) . '...'
							]);
						} else {
                            // Optionally log handled bad token errors at a lower level, e.g., INFO
                            \Log::info('[PushService] Handled known bad token error', [
                                'status'   => $response->status(),
								'response' => $errorData, 
                                'token'    => substr($token, 0, 15) . '...'
                            ]);
                        }
					}
				} catch (\Throwable $e) {
					$failureCount++;
					\Log::error('[PushService] Exception while sending notification', [
						'error' => $e->getMessage(),
						'token' => substr($token, 0, 15) . '...'
					]);
				}
			}

			\Log::info('[PushService] Notification dispatch complete', [
				'success' => $successCount,
				'failures' => $failureCount,
				'total'    => count($processedReceivers)
			]);
		})->afterResponse();
	}

	public function sendAllNotification(?string $title = null, mixed $data = [], ?string $firebaseTitle = ''): void
	{
		dispatch(function () use ($title, $data, $firebaseTitle) {

			User::select([
				'id',
				'deleted_at',
				'active',
				'email_verified_at',
				'phone_verified_at',
				'firebase_token',
			])
				->where('active', 1)
				->where(fn($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))
				->whereNotNull('firebase_token')
				->orderBy('id')
				->chunk(100, function ($users) use ($title, $data, $firebaseTitle) {

					$firebaseTokens = $users?->pluck('firebase_token', 'id')?->toArray();

					$receives = [];

					Log::error('firebaseTokens ', [
						'count' => !empty($firebaseTokens) ? count($firebaseTokens) : $firebaseTokens
					]);

					foreach ($firebaseTokens as $firebaseToken) {

						if (empty($firebaseToken)) {
							continue;
						}

						$receives[] = array_filter($firebaseToken, fn($item) => !empty($item));
					}

					$receives = array_merge(...$receives);

					Log::error('count rece ' . count($receives));

					$this->sendNotification(
						$receives,
						$title,
						data_get($data, 'id'),
						$data,
						array_keys(is_array($firebaseTokens) ? $firebaseTokens : []),
						$firebaseTitle
					);

				});

		})->afterResponse();

	}

	private function updateToken(): string
	{
		// Possible credential file locations (most-specific first). You can also
		// set FIREBASE_CREDENTIALS_PATH in .env to point anywhere on disk.
		$paths = array_filter([
			// Custom override via environment variable
			env('FIREBASE_CREDENTIALS_PATH'),

			// Common filenames we look for inside storage/app
			storage_path('app/google-service-account.json'),      // singular "service"
			storage_path('app/google-services-account.json'),     // plural   "services" (Play-style)

			// Nested location that some deploy scripts use
			storage_path('app/firebase/service-account.json'),
		]);

		$credsPath = collect($paths)->first(fn($p) => !empty($p) && file_exists($p));

		if (!$credsPath) {
			\Log::error('[PushService] Firebase credentials file not found', ['checked' => $paths]);
			return '';
		}

		try {
			$googleClient = new Client;
			$googleClient->setAuthConfig($credsPath);
			$googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');
			$token = $googleClient->fetchAccessTokenWithAssertion()['access_token'] ?? '';
			if (empty($token)) {
				\Log::error('[PushService] Empty access_token from Google credentials');
			}
			return $token;
		} catch (\Throwable $e) {
			\Log::error('[PushService] updateToken failed', ['err' => $e->getMessage()]);
			return '';
		}
	}

	public function newOrderNotification(Order $order): void
	{
		try {
			\Log::info('[PushService] Starting new order notification for order #' . $order->id);
			
			// Get admin tokens
		$adminFirebaseTokens = User::with(['roles' => fn($q) => $q->where('name', 'admin')])
			->whereHas('roles', fn($q) => $q->where('name', 'admin'))
			->whereNotNull('firebase_token')
				->where('firebase_token', '!=', '')
				->where('firebase_token', '!=', '[]')
				->where('firebase_token', '!=', 'null')
			->pluck('firebase_token', 'id')
			->toArray();

			// Get seller tokens
		$sellersFirebaseTokens = User::with([
			'shop' => fn($q) => $q->where('id', $order->shop_id)
		])
			->whereHas('shop', fn($q) => $q->where('id', $order->shop_id))
			->whereNotNull('firebase_token')
				->where('firebase_token', '!=', '')
				->where('firebase_token', '!=', '[]')
				->where('firebase_token', '!=', 'null')
			->pluck('firebase_token', 'id')
			->toArray();

		$aTokens = [];
		$sTokens = [];
			$adminIds = [];
			$sellerIds = [];

			// Process admin tokens
			foreach ($adminFirebaseTokens as $userId => $adminToken) {
				if (is_array($adminToken)) {
					foreach ($adminToken as $token) {
						if (!empty($token)) {
							$aTokens[] = $token;
							$adminIds[] = $userId;
						}
					}
				} elseif (!empty($adminToken)) {
					$aTokens[] = $adminToken;
					$adminIds[] = $userId;
		}
			}

			// Process seller tokens
			foreach ($sellersFirebaseTokens as $userId => $sellerToken) {
				if (is_array($sellerToken)) {
					foreach ($sellerToken as $token) {
						if (!empty($token)) {
							$sTokens[] = $token;
							$sellerIds[] = $userId;
						}
					}
				} elseif (!empty($sellerToken)) {
					$sTokens[] = $sellerToken;
					$sellerIds[] = $userId;
				}
			}

			$allTokens = array_values(array_unique(array_merge($aTokens, $sTokens)));
			$allUserIds = array_unique(array_merge($adminIds, $sellerIds));
			
			\Log::info('[PushService] Preparing order notification delivery', [
				'order_id' => $order->id,
				'admin_tokens_count' => count($aTokens),
				'seller_tokens_count' => count($sTokens),
				'total_tokens' => count($allTokens),
				'total_users' => count($allUserIds)
			]);

			// Send notification to admins and sellers
			if (!empty($allTokens)) {
				$notificationData = $order->only(['id', 'status', 'delivery_type']);
				$notificationData['type'] = 'new_order';
				$notificationData['title'] = 'New Order #' . $order->id;
				$notificationData['body'] = __('errors.' . ResponseError::NEW_ORDER, ['id' => $order->id], $this->language);
				
				\Log::info('[PushService] Sending admin/seller notification', [
					'tokens' => count($allTokens),
					'users' => count($allUserIds)
				]);
				
				$this->sendNotification(
					$allTokens,
					$notificationData,
					$allUserIds
				);
			}

			// Send notification to the user who placed the order
			if ($order->user && !empty($order->user->firebase_token)) {
				$userTokens = [];
				
				if (is_array($order->user->firebase_token)) {
					foreach ($order->user->firebase_token as $token) {
						if (!empty($token)) {
							$userTokens[] = $token;
						}
					}
				} elseif (!empty($order->user->firebase_token)) {
					$userTokens[] = $order->user->firebase_token;
				}

				if (!empty($userTokens)) {
					\Log::info('[PushService] Sending user order notification', [
						'order_id' => $order->id,
						'user_id' => $order->user->id,
						'tokens_count' => count($userTokens)
					]);

					$notificationData = $order->only(['id', 'status', 'delivery_type']);
					$notificationData['type'] = 'new_order';
					$notificationData['title'] = 'Order #' . $order->id;
					$notificationData['body'] = __('Your order #:id has been received!', ['id' => $order->id], $this->language);

		$this->sendNotification(
						$userTokens,
						$notificationData,
						[$order->user_id]
					);
				}
			}
			
			\Log::info('[PushService] Completed new order notification for order #' . $order->id);
		} catch (\Throwable $e) {
			\Log::error('[PushService] Error in newOrderNotification', [
				'error' => $e->getMessage(),
				'order_id' => $order->id,
				'trace' => $e->getTraceAsString()
			]);
		}
	}

	private function projectId(): string
	{
		// Try DB first, then env variable for flexibility during installation / local dev
		$projectId = Settings::where('key', 'project_id')->value('value');

		if (empty($projectId)) {
			$projectId = env('FIREBASE_PROJECT_ID', '');
			\Log::info('[PushService] projectId fallback to env', ['value' => $projectId]);
		}

		return (string)$projectId;
	}

	/**
	 * Clean up invalid Firebase tokens
	 * This can be called from a scheduled command
	 */
	public function cleanInvalidTokens(): void
	{
		try {
			\Log::info('[PushService] Starting token cleanup');
			
			// Clear empty tokens
			$emptyTokensCount = DB::table('users')
				->where(function($query) {
					$query->whereNull('firebase_token')
						->orWhere('firebase_token', '')
						->orWhere('firebase_token', '[]')
						->orWhere('firebase_token', 'null');
				})
				->update(['firebase_token' => null]);
			
			\Log::info("[PushService] Cleared $emptyTokensCount empty tokens");
			
			// Find and fix any remaining array tokens
			$arrayTokensCount = DB::table('users')
				->whereRaw('firebase_token LIKE ?', ['[%'])
				->update([
					'firebase_token' => DB::raw('JSON_UNQUOTE(JSON_EXTRACT(firebase_token, "$[0]"))')
				]);
				
			\Log::info("[PushService] Fixed $arrayTokensCount array tokens");
			
			// Check for duplicate tokens
			$duplicateTokens = DB::table('users')
				->select('firebase_token', DB::raw('COUNT(*) as count'))
				->whereNotNull('firebase_token')
				->groupBy('firebase_token')
				->having('count', '>', 1)
				->get();
				
			if ($duplicateTokens->count() > 0) {
				\Log::warning('[PushService] Found duplicate tokens', [
					'count' => $duplicateTokens->count(),
					'tokens' => $duplicateTokens->pluck('count', 'firebase_token')->toArray()
				]);
				
				// Keep only the most recently updated user with each token
				foreach ($duplicateTokens as $duplicate) {
					$token = $duplicate->firebase_token;
					
					// Get all users with this token except the most recently updated one
					$usersToNullify = DB::table('users')
						->where('firebase_token', $token)
						->orderBy('updated_at', 'desc')
						->skip(1) // Skip the most recent one
						->take(100) // Limit to avoid large updates
						->pluck('id');
						
					if ($usersToNullify->count() > 0) {
						DB::table('users')
							->whereIn('id', $usersToNullify)
							->update(['firebase_token' => null]);
							
						\Log::info("[PushService] Cleared duplicate token from " . $usersToNullify->count() . " users");
					}
				}
			}
			
			\Log::info('[PushService] Token cleanup completed successfully');
		} catch (\Exception $e) {
			\Log::error('[PushService] Error cleaning tokens: ' . $e->getMessage(), [
				'trace' => $e->getTraceAsString()
			]);
		}
	}

	private function normalizeTokens(array $tokens): array
	{
		$normalizedTokens = [];
		foreach ($tokens as $token) {
			if (is_array($token)) {
				foreach ($token as $item) {
					if (!empty($item) && is_string($item)) {
						$normalizedTokens[] = $item;
					}
				}
			} elseif (is_string($token) && !empty($token)) {
				$trimmed = trim($token);

				// If token looks like a JSON array (e.g. "[\"tok1\",\"tok2\"]") decode it
				if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
					$decoded = json_decode($trimmed, true);
					if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
						foreach ($decoded as $tok) {
							if (!empty($tok) && is_string($tok)) {
								$normalizedTokens[] = $tok;
							}
						}
						continue;
					}
				}

				// If token contains comma-separated list, split it
				if (str_contains($trimmed, ',')) {
					foreach (explode(',', $trimmed) as $tok) {
						$tok = trim($tok, " \"'[]");
						if (!empty($tok)) {
							$normalizedTokens[] = $tok;
						}
					}
					continue;
				}

				// Fallback – assume the string itself is a single token
				$normalizedTokens[] = $trimmed;
			}
		}
		return $normalizedTokens;
	}

	public function statusChange(int $id, ?string $status = null): array
	{
		return DB::transaction(function () use ($id, $status) {
			// … entire original body …
		});
	}

	public function create(array $data): array
	{
		$data += ['created_by' => auth('sanctum')->id()];
		// …
	}
}
