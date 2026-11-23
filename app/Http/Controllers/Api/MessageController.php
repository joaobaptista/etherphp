<?php

namespace App\Http\Controllers\Api;

use App\Actions\Messages\CreateMessageAction;
use App\Actions\Messages\DeleteMessageAction;
use App\Actions\Messages\UpdateMessageAction;
use App\Data\CreateMessageData;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMessageRequest;
use App\Http\Requests\UpdateMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Models\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

#[Group('Mensagens', 'Cartas e mensagens agendadas.', weight: 60)]
class MessageController extends Controller
{
    /**
     * Listar mensagens
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Message::query()->where('user_id', $user->id);

        if (is_string($request->query('status')) && $request->query('status') !== '') {
            $query->where('status', $request->query('status'));
        }
        if (is_string($request->query('recipient')) && $request->query('recipient') !== '') {
            $like = '%'.strtolower($request->query('recipient')).'%';
            $query->whereRaw('LOWER(recipient_name) LIKE ?', [$like]);
        }

        $messages = $query->orderByDesc('created_at')->get();

        return response()->json(MessageResource::collection($messages)->resolve());
    }

    /**
     * Ver uma mensagem
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $message = Message::query()->find($id);

        if ($message === null || ! Gate::forUser($user)->allows('view', $message)) {
            return response()->json(['detail' => 'Mensagem não encontrada'], 404);
        }

        return (new MessageResource($message))->response();
    }

    /**
     * Criar uma mensagem
     *
     * Com `capsuleId`, a mensagem fica ligada à cápsula.
     */
    public function store(CreateMessageRequest $request, CreateMessageAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $v */
        $v = $request->validated();

        $message = $action->execute($user, new CreateMessageData(
            title: (string) $v['title'],
            content: (string) ($v['content'] ?? ''),
            recipientName: isset($v['recipientName']) ? (string) $v['recipientName'] : null,
            recipientEmail: isset($v['recipientEmail']) ? (string) $v['recipientEmail'] : null,
            recipientPhone: isset($v['recipientPhone']) ? (string) $v['recipientPhone'] : null,
            deliveryType: (string) ($v['deliveryType'] ?? 'date'),
            deliveryDate: isset($v['deliveryDate']) ? (string) $v['deliveryDate'] : null,
            deliveryTime: (string) ($v['deliveryTime'] ?? '09:00'),
            guardianProtected: (bool) ($v['guardianProtected'] ?? true),
            emailNotification: (bool) ($v['emailNotification'] ?? true),
            whatsappNotification: (bool) ($v['whatsappNotification'] ?? false),
            mediaFiles: is_array($v['mediaFiles'] ?? null) ? array_values($v['mediaFiles']) : [],
            category: (string) ($v['category'] ?? 'Cartas de Amor'),
            status: (string) ($v['status'] ?? 'scheduled'),
        ));

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /**
     * Atualizar uma mensagem
     */
    public function update(UpdateMessageRequest $request, string $id, UpdateMessageAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $message = Message::query()->find($id);

        if ($message === null || ! Gate::forUser($user)->allows('update', $message)) {
            return response()->json(['detail' => 'Mensagem não encontrada'], 404);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();
        $updated = $action->execute($message, $validated);

        return (new MessageResource($updated))->response();
    }

    /**
     * Excluir uma mensagem
     */
    public function destroy(Request $request, string $id, DeleteMessageAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $message = Message::query()->find($id);

        if ($message === null || ! Gate::forUser($user)->allows('delete', $message)) {
            return response()->json(['detail' => 'Mensagem não encontrada'], 404);
        }

        $action->execute($message);

        return response()->json(['status' => 'deleted', 'id' => $id]);
    }

    /**
     * Sugerir texto de mensagem
     *
     * Gera um rascunho a partir do destinatário, da ocasião e do tom.
     */
    #[BodyParameter('recipientName', 'Nome de quem vai receber.', type: 'string', example: 'Ana')]
    #[BodyParameter('occasion', 'Ocasião da mensagem.', type: 'string', example: 'aniversário')]
    #[BodyParameter('tone', 'Tom do texto.', type: 'string', default: 'afetuoso')]
    #[BodyParameter('prompt', 'Pedido livre.', type: 'string')]
    public function generateAi(Request $request): JsonResponse
    {
        $recipient = trim((string) $request->input('recipientName', 'você'));
        $recipient = $recipient !== '' ? $recipient : 'você';
        $prompt = trim((string) $request->input('prompt', ''));
        $occasion = trim((string) $request->input('occasion', ''));
        $tone = trim((string) $request->input('tone', 'afetuoso'));

        $topic = $occasion !== '' ? $occasion : ($prompt !== '' ? $prompt : 'este momento especial');
        $intro = match (strtolower($tone)) {
            'sabio', 'sábio', 'sabedoria' => "Querido(a) {$recipient},\n\nReuni aqui alguns ensinamentos que aprendi ao longo da vida sobre {$topic}.",
            'gratidao', 'gratidão' => "Querido(a) {$recipient},\n\nQuero agradecer profundamente por tudo o que vivemos juntos em {$topic}.",
            default => "Querido(a) {$recipient},\n\nGuardei estas palavras com muito carinho para que chegassem até você sobre {$topic}.",
        };

        return response()->json([
            'title' => "Para {$recipient} — {$topic}",
            'content' => "{$intro}\n\nQue estas palavras tragam paz, carinho e a certeza de tudo o que construímos juntos.",
            'source' => 'server_fallback',
        ]);
    }
}
