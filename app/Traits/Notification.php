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
		dispatch(function () use ($receivers, $message, $title, $data, $userIds, $firebaseTitle) {
			if (empty($receivers)) {
				return;
			}

			\Log::info('[PushService] Notification Data', [
				'receivers' => $receivers,
				'title' => $title,
				'message' => $message,
				'data' => $data,
				'userIds' => $userIds,
				'firebaseTitle' => $firebaseTitle
			]);


			$type = data_get($data, 'order.type');

			if (is_array($userIds) && count($userIds) > 0) {
				\Log::info('[PushService] Storing Notification for Users', [
					'userIds' => $userIds,
					'type' => $type ?? data_get($data, 'type'),
					'title' => $title,
					'message' => $message,
					'data' => $data
				]);
				(new PushNotificationService)->storeMany([
					'type' 	=> $type ?? data_get($data, 'type'),
					'title' => $title,
					'body' 	=> $message,
					'data' 	=> $data,
					'sound' => 'default',
				], $userIds);
			}

			$url = "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";

			$token = $this->updateToken();

			\Log::info('[PushService] Sending Notification to FCM 111', [
				'url' => $url,
				'token' => $token
			]);

			$headers = [
				'Authorization' => "Bearer $token",
				'Content-Type'  => 'application/json'
			];

			foreach ($receivers as $receiver) {
				\Log::info('[PushService] inside', [
					'receiver' => $receiver,
					'firebaseTitle' => $firebaseTitle ?? $title,
					'message' => $message,
					'data' => [
						'id' => (string)($data['id'] ?? ''),
						'status' => (string)($data['status'] ?? ''),
						'type' => (string)($data['type'] ?? '')
					]
				]);
				Http::withHeaders($headers)->post($url, [ // $request =
					'message' => [
						'token' => $receiver,
						'notification' => [
							'title' => $firebaseTitle ?? $title,
							'body' 	=> $message,
						],
						'data' => [
							'id'     => (string)($data['id'] 	 ?? ''),
							'status' => (string)($data['status'] ?? ''),
							'type'   => (string)($data['type'] 	 ?? '')
						],
						'android' => [
							'notification' => [
								'sound' => 'default',
							]
						],
						'apns' => [
							'payload' => [
								'aps' => [
									'sound' => 'default'
								]
							]
						]
					]
				]);
			}

		})->afterResponse();
	}


	public function sendNotification(
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
				'title' => $data['title'] ?? '',
				'body' => $data['body'] ?? '',
				'userIds' => $userIds
			]);

			// Store notification in database if userIds are provided
			if (!empty($userIds)) {
				try {
					foreach ($userIds as $userId) {
						PushNotification::create([
							'user_id' => $userId,
							'type' => $data['type'] ?? 'system',
							'title' => $data['title'] ?? '',
							'body' => $data['body'] ?? '',
							'data' => $data
						]);
					}
				} catch (\Exception $e) {
					\Log::error('[PushService] Failed to store notification: ' . $e->getMessage());
				}
			}

			// Get Firebase token
			try {
				$token = $this->updateToken();
				if (empty($token)) {
					\Log::error('[PushService] Failed to get Firebase token');
					return;
				}
			} catch (\Exception $e) {
				\Log::error('[PushService] Error getting Firebase token: ' . $e->getMessage());
				return;
			}

			// Process receivers to ensure they are strings
			$processedReceivers = [];
			foreach ($receivers as $receiver) {
				if (is_array($receiver)) {
					$receiver = $receiver[0] ?? null;
				}
				if (!empty($receiver) && is_string($receiver)) {
					$processedReceivers[] = $receiver;
				}
			}

			if (empty($processedReceivers)) {
				\Log::error('[PushService] No valid receivers after processing');
				return;
			}

			\Log::info('[PushService] Sending to ' . count($processedReceivers) . ' tokens');

			$successCount = 0;
			$failureCount = 0;

			// Send to each token individually
			foreach ($processedReceivers as $token) {
				try {
					$notification = [
						'message' => [
							'token' => $token, // Individual token, not array
							'notification' => [
								'title' => $data['title'] ?? '',
								'body' => $data['body'] ?? '',
							],
							'data' => is_array($data) ? $data : ['message' => (string)$data],
							'android' => [
								'priority' => 'high',
								'notification' => [
									'priority' => 'high',
									'sound' => 'default',
									'channel_id' => 'high_importance_channel',
									'default_sound' => true,
									'default_vibrate_timings' => true,
									'default_light_settings' => true
								]
							],
							'apns' => [
								'headers' => [
									'apns-priority' => '10'
								],
								'payload' => [
									'aps' => [
										'sound' => 'default',
										'badge' => 1,
										'content-available' => 1
									]
								]
							]
						]
					];

					$response = Http::withHeaders([
						'Authorization' => 'Bearer ' . $token,
						'Content-Type' => 'application/json',
					])
					->timeout(10)
					->post('https://fcm.googleapis.com/v1/projects/' . $this->projectId() . '/messages:send', $notification);

					if ($response->successful()) {
						$successCount++;
					} else {
						$failureCount++;
						\Log::error('[PushService] Failed to send notification to token', [
							'token_prefix' => substr($token, 0, 15) . '...',
							'status' => $response->status(),
							'response' => $response->json()
						]);
						
						// Handle specific FCM errors
						$error = $response->json()['error']['message'] ?? null;
						if ($error && (
							strpos($error, 'InvalidRegistration') !== false ||
							strpos($error, 'NotRegistered') !== false ||
							strpos($error, 'MismatchSenderId') !== false
						)) {
							\Log::warning('[PushService] Invalid token detected, should be removed', [
								'token_prefix' => substr($token, 0, 15) . '...',
								'error' => $error
							]);
							
							// TODO: Implement logic to remove invalid tokens from users table
						}
					}
				} catch (\Exception $e) {
					$failureCount++;
					\Log::error('[PushService] Exception sending notification to token', [
						'token_prefix' => substr($token, 0, 15) . '...',
						'error' => $e->getMessage()
					]);
				}
			}

			\Log::info('[PushService] Notification sending complete', [
				'success' => $successCount,
				'failures' => $failureCount,
				'total' => count($processedReceivers)
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
		try {
			return Cache::remember('firebase_auth_token', 300, function () {
				$googleClient = new Client;
				$googleClient->setAuthConfig(storage_path('app/google-service-account.json'));
				$googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');

				$token = $googleClient->fetchAccessTokenWithAssertion()['access_token'];
				
				if (empty($token)) {
					\Log::error('[PushService] Failed to get Firebase token - empty token returned');
					throw new \Exception('Empty Firebase token returned');
				}

				return $token;
			});
		} catch (\Throwable $e) {
			\Log::error('[PushService] Failed to get Firebase token', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			throw $e;
		}
	}

	public function newOrderNotification(Order $order): void
	{
		try {
			// Get admin tokens
			$adminFirebaseTokens = User::with(['roles' => fn($q) => $q->where('name', 'admin')])
				->whereHas('roles', fn($q) => $q->where('name', 'admin'))
				->whereNotNull('firebase_token')
				->pluck('firebase_token', 'id')
				->toArray();

			// Get seller tokens
			$sellersFirebaseTokens = User::with([
				'shop' => fn($q) => $q->where('id', $order->shop_id)
			])
				->whereHas('shop', fn($q) => $q->where('id', $order->shop_id))
				->whereNotNull('firebase_token')
				->pluck('firebase_token', 'id')
				->toArray();

			$aTokens = [];
			$sTokens = [];

			// Process admin tokens
			foreach ($adminFirebaseTokens as $userId => $adminToken) {
				if (is_array($adminToken)) {
					$aTokens = array_merge($aTokens, array_filter($adminToken));
				} elseif (!empty($adminToken)) {
					$aTokens[] = $adminToken;
				}
			}

			// Process seller tokens
			foreach ($sellersFirebaseTokens as $userId => $sellerToken) {
				if (is_array($sellerToken)) {
					$sTokens = array_merge($sTokens, array_filter($sellerToken));
				} elseif (!empty($sellerToken)) {
					$sTokens[] = $sellerToken;
				}
			}

			$allTokens = array_values(array_unique(array_merge($aTokens, $sTokens)));
			
			\Log::info('[PushService] Sending order notification to admins and sellers', [
				'order_id' => $order->id,
				'admin_tokens_count' => count($aTokens),
				'seller_tokens_count' => count($sTokens),
				'total_tokens' => count($allTokens)
			]);

			// Send notification to admins and sellers
			if (!empty($allTokens)) {
				$notificationData = $order->only(['id', 'status', 'delivery_type']);
				$notificationData['type'] = PushNotification::NEW_ORDER;
				$notificationData['title'] = 'New Order #' . $order->id;
				$notificationData['body'] = __('errors.' . ResponseError::NEW_ORDER, ['id' => $order->id], $this->language);
				
				$this->sendNotification(
					$allTokens,
					$notificationData,
					array_merge(array_keys($adminFirebaseTokens), array_keys($sellersFirebaseTokens))
				);
			}

			// Send notification to the user who placed the order
			if ($order->user && !empty($order->user->firebase_token)) {
				$userTokens = is_array($order->user->firebase_token) ? 
					array_filter($order->user->firebase_token) : 
					[$order->user->firebase_token];

				if (!empty($userTokens)) {
					\Log::info('[PushService] Sending order notification to user', [
						'order_id' => $order->id,
						'user_id' => $order->user->id,
						'tokens_count' => count($userTokens)
					]);

					$notificationData = $order->only(['id', 'status', 'delivery_type']);
					$notificationData['type'] = PushNotification::NEW_ORDER;
					$notificationData['title'] = 'Order #' . $order->id;
					$notificationData['body'] = __('Your order #:id has been received!', ['id' => $order->id], $this->language);
					
					$this->sendNotification(
						$userTokens,
						$notificationData,
						[$order->user_id]
					);
				}
			}
		} catch (\Throwable $e) {
			\Log::error('[PushService] Error in newOrderNotification', [
				'error' => $e->getMessage(),
				'order_id' => $order->id,
				'trace' => $e->getTraceAsString()
			]);
		}
	}

	private function projectId()
	{
		return Settings::where('key', 'project_id')->value('value');
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
}
