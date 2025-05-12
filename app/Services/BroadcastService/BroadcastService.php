<?php

namespace App\Services\BroadcastService;

use App\Mail\BroadcastMailable;
use App\Models\User;
use App\Models\Broadcast;
use App\Services\CoreService;
use App\Traits\Notification;
use Illuminate\Support\Facades\Mail;

class BroadcastService extends CoreService
{
    use Notification; // gives sendNotificationSimple

    protected function getModelClass(): string
    {
        // not persisting; we return User class
        return User::class;
    }

    /**
     * Send broadcast to selected user groups through chosen channels.
     *
     * @param array $payload validated data from request
     * @return array stats
     */
    public function send(array $payload): array
    {
        $groups   = $payload['groups'];      // ['admin','seller'] etc.
        $channels = $payload['channels'];    // ['push','email']

        $query = User::query();
        if (!in_array('user', $groups)) {
            // map group names to roles table
            $query->whereHas('roles', function ($q) use ($groups) {
                $q->whereIn('name', $groups);
            });
        }

        $stats = [
            'emailed' => 0,
            'pushed'  => 0,
            'total'   => 0,
        ];

        $query->where(function ($q) {
            $q->whereNotNull('email')->orWhereNotNull('firebase_token');
        })->chunkById(500, function ($users) use ($payload, $channels, &$stats) {
            if (in_array('push', $channels)) {
                $tokens   = [];
                $userIds  = [];
                foreach ($users as $u) {
                    if (empty($u->firebase_token)) continue;
                    $userIds[] = $u->id;
                    $tokens = array_merge($tokens, is_array($u->firebase_token) ? $u->firebase_token : [$u->firebase_token]);
                }
                if (!empty($tokens)) {
                    $this->sendNotification(
                        $tokens,
                        $payload['body'], // message
                        $payload['title'],
                        [
                            'id'      => 0,
                            'type'    => 'broadcast',
                            'channels'=> $channels,
                        ] + $payload,
                        $userIds,
                    );
                    $stats['pushed'] += count($tokens);
                }
            }

            if (in_array('email', $channels)) {
                foreach ($users as $u) {
                    if (!$u->email) continue;
                    Mail::to($u->email)->queue(new BroadcastMailable($payload['title'], $payload['body']));
                    $stats['emailed']++;
                }
            }
            $stats['total'] += $users->count();
        });

        // Save broadcast stats
        $broadcast = Broadcast::create([
            'title'    => $payload['title'],
            'body'     => $payload['body'],
            'channels' => $channels,
            'groups'   => $groups,
            'stats'    => $stats,
        ]);

        return [
            'id'    => $broadcast->id,
            'stats' => $stats,
        ];
    }

    /**
     * Resend an existing broadcast by its ID.
     */
    public function resend(int $id): array
    {
        $broadcast = Broadcast::findOrFail($id);

        return $this->send($broadcast->toArray());
    }
} 