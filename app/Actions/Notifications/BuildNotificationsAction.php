<?php

namespace App\Actions\Notifications;

use App\Enums\DeliveryTypeEnum;
use App\Enums\MessageStatusEnum;
use App\Models\Capsule;
use App\Models\Guardian;
use App\Models\Memory;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BuildNotificationsAction
{
    private const MAX_ITEMS = 20;

    /**
     * @return array{items: list<array<string, mixed>>, unreadCount: int}
     */
    public function execute(User $user): array
    {
        $items = [];

        if ($user->created_at !== null) {
            $items[] = [
                'id' => 'account',
                'type' => 'account',
                'title' => 'Conta criada',
                'desc' => 'Bem-vindo ao Ethernity. Seu cofre de memórias está pronto.',
                'tab' => 'memories',
                'createdAt' => $user->created_at->toIso8601String(),
                '_ts' => $user->created_at->getTimestamp(),
            ];
        }

        $guardians = Guardian::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS)
            ->get();

        foreach ($guardians as $g) {
            if ($g->created_at === null) {
                continue;
            }
            $items[] = [
                'id' => "guardian-{$g->id}",
                'type' => 'guardian',
                'title' => 'Guardião cadastrado',
                'subject' => $g->name,
                'tab' => 'guardian',
                'createdAt' => $g->created_at->toIso8601String(),
                '_ts' => $g->created_at->getTimestamp(),
            ];
        }

        $capsules = Capsule::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS)
            ->get();

        foreach ($capsules as $c) {
            if ($c->created_at === null) {
                continue;
            }
            $delivery = match (true) {
                $c->delivery_type === DeliveryTypeEnum::Posthumous => 'Entrega póstuma.',
                $c->scheduled_date !== null => 'Entrega em '.$c->scheduled_date->format('d/m/Y').'.',
                default => 'Data de entrega ainda não definida.',
            };
            $recipient = is_array($c->recipient) ? (string) ($c->recipient['name'] ?? '') : '';

            $items[] = [
                'id' => "capsule-{$c->id}",
                'type' => 'capsule',
                'title' => 'Cápsula criada',
                'subject' => $c->title,
                'recipient' => $recipient,
                'detail' => $delivery,
                'tab' => 'capsules',
                'createdAt' => $c->created_at->toIso8601String(),
                '_ts' => $c->created_at->getTimestamp(),
            ];
        }

        $messages = Message::query()
            ->where('user_id', $user->id)
            ->whereNull('device_id')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS)
            ->get();

        foreach ($messages as $m) {
            if ($m->created_at === null) {
                continue;
            }
            $items[] = [
                'id' => "message-{$m->id}",
                'type' => 'message',
                'title' => $m->status === MessageStatusEnum::Draft ? 'Rascunho de mensagem salvo' : 'Mensagem agendada',
                'subject' => $m->title,
                'recipient' => $m->recipient_name ?? '',
                'tab' => 'messages_new',
                'createdAt' => $m->created_at->toIso8601String(),
                '_ts' => $m->created_at->getTimestamp(),
            ];
        }

        $uploads = Memory::query()
            ->select([
                DB::raw('CAST(created_at AS DATE) as day'),
                DB::raw('COUNT(*) as total'),
                DB::raw('MAX(created_at) as last_at'),
            ])
            ->where('author_id', $user->id)
            ->groupBy(DB::raw('CAST(created_at AS DATE)'))
            ->orderByDesc('day')
            ->limit(self::MAX_ITEMS)
            ->get();

        foreach ($uploads as $u) {
            $lastAt = $u->getAttribute('last_at');
            $day = (string) $u->getAttribute('day');
            $total = (int) $u->getAttribute('total');
            if (! is_string($lastAt) || $lastAt === '') {
                continue;
            }
            $carbonLast = Carbon::parse($lastAt);
            $dayFormatted = Carbon::parse($day)->format('d/m/Y');
            $plural = $total === 1 ? 'arquivo criptografado e salvo' : 'arquivos criptografados e salvos';

            $items[] = [
                'id' => "vault-{$dayFormatted}",
                'type' => 'vault',
                'title' => 'Arquivos guardados no cofre',
                'desc' => "{$total} {$plural} no seu cofre.",
                'tab' => 'vault',
                'createdAt' => $carbonLast->toIso8601String(),
                '_ts' => $carbonLast->getTimestamp(),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $b['_ts'] <=> $a['_ts']);
        $sliced = array_slice($items, 0, self::MAX_ITEMS);

        $readAtTs = $user->notifications_read_at?->getTimestamp();
        $unreadCount = 0;
        $formatted = [];

        foreach ($sliced as $item) {
            $unread = $readAtTs === null || $item['_ts'] > $readAtTs;
            if ($unread) {
                $unreadCount++;
            }
            unset($item['_ts']);
            $item['unread'] = $unread;
            $formatted[] = $item;
        }

        return [
            'items' => $formatted,
            'unreadCount' => $unreadCount,
        ];
    }
}
