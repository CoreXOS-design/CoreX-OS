<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AI\Ellie\EllieAgentService;

class EllieController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // Load user's conversations
        $showArchived = $request->query('archived') == '1';

        $conversationsQuery = AiConversation::where('user_id', $user->id);

        if (!$showArchived) {
            $conversationsQuery->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            });
        }

        $conversations = $conversationsQuery
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // ELLIE_NEW_CONVO_2026
        // If user clicked New, create a fresh conversation and redirect to it
        if ($request->query('new') == '1') {
            $c = AiConversation::create([
                'user_id' => $user->id,
                'title' => null,
                'last_message_at' => now(),
            ]);

            return redirect()->route('ellie.index', ['conversation_id' => $c->id]);
        }

        // Load active conversation if provided
        $conversationId = $request->query('conversation_id');

        $activeConversation = null;
        $messages = collect();

        if ($conversationId) {
            $activeConversation = AiConversation::where('user_id', $user->id)
                ->where('id', $conversationId)
                ->first();

            if ($activeConversation) {
                $messages = AiMessage::where('conversation_id', $activeConversation->id)
                    ->orderBy('id')
                    ->get();
            }
        }

        return view('ellie.index', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'messages' => $messages,
        ]);
    }

    /**
     * Answer one message.
     *
     * ELLIE_V2_2026 — this used to be ~340 lines of keyword heuristics that
     * guessed what to retrieve BEFORE the model saw the question, then made a
     * single tool-less call to the Python service. That capped Ellie at roughly
     * 60% useful: she could not expand "OTP" to "Offer to Purchase", could not
     * re-search when the first attempt came back thin, and could not read a
     * single row of live data — so agents were told "I don't have access to
     * that" about documents that were embedded and searchable the whole time.
     *
     * Retrieval now happens through tools the model calls on demand, inside
     * Laravel where permissions and Eloquent live. The deterministic shortcuts
     * that used to sit here (prime rate, transfer costs) are tools too, so the
     * model decides when they apply instead of a str_contains() guess.
     *
     * Spec: .ai/specs/ellie-v2.md.
     */
    public function send(Request $request, EllieAgentService $ellie)
    {
        $user = Auth::user();

        $data = $request->validate([
            'conversation_id' => 'nullable|integer',
            'message'         => 'required|string|max:20000',
            'page_path'       => 'nullable|string|max:512',
            'page_title'      => 'nullable|string|max:255',
        ]);

        // Create or load conversation
        if (!empty($data['conversation_id'])) {
            $conversation = AiConversation::where('user_id', $user->id)
                ->where('id', $data['conversation_id'])
                ->firstOrFail();
        } else {
            $conversation = AiConversation::create([
                'user_id' => $user->id,
                'title' => null,
                'last_message_at' => now(),
            ]);
        }

        // Prior turns for context — fetched BEFORE the new message is stored so
        // the agent service receives history and the current message separately.
        $history = AiMessage::where('conversation_id', $conversation->id)
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('id')
            ->take(10)
            ->get()
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => (string) $m->content])
            ->values()
            ->all();

        // Store user message
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $data['message'],
        ]);

        $answer = $ellie->answer(
            message: $data['message'],
            user: $user,
            history: $history,
            pageContext: [
                'path'  => $data['page_path'] ?? null,
                'title' => $data['page_title'] ?? null,
            ],
        );

        $reply = $answer['reply'];

        \Log::info('ELLIE_SEND_RES', [
            'user_id'    => (int) $user->id,
            'ok'         => (bool) $answer['ok'],
            'tools_used' => $answer['tools_used'],
        ]);

        // Store assistant message
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'assistant',
            'content' => $reply,
        ]);

        // ELLIE_AUTOTITLE_2026
        // Auto-title conversation after first exchange (only if title is empty)
        if (empty($conversation->title)) {
            $title = $this->generateAutoTitle((string)($data['message'] ?? ''), (string)$reply);
            if ($title !== '') {
                $conversation->title = $title;
                $conversation->save();
            }
        }

        // Update conversation timestamp
        $conversation->update([
            'last_message_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'reply' => $reply,
        ]);
    }

      private function generateAutoTitle(string $userMessage, string $assistantReply): string
      {
          $s = trim($userMessage);
          $s = preg_replace('/\s+/u', ' ', $s ?? '');
          // Remove most punctuation but keep words/numbers/spaces and a few separators
          $s = preg_replace('/[^\pL\pN\s\-\&\/]/u', '', $s ?? '');
          $s = trim($s);

          if ($s === '') {
              return 'New Chat';
          }

          $words = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
          $maxWords = 6;

          if (count($words) > $maxWords) {
              $words = array_slice($words, 0, $maxWords);
          }

          $t = trim(implode(' ', $words));

          // Hard cap
          if (function_exists('mb_substr')) {
              $t = mb_substr($t, 0, 60);
          } else {
              $t = substr($t, 0, 60);
          }

          $t = trim($t);
          if ($t === '') {
              return 'New Chat';
          }

          return (string) Str::of($t)->title();
      }


      public function rename(Request $request)
      {
          $user = Auth::user();

          $data = $request->validate([
              'conversation_id' => 'required|integer',
              'title' => 'required|string|max:120',
              'return_archived' => 'nullable|string',
          ]);

          $conversation = AiConversation::where('user_id', $user->id)
              ->where('id', $data['conversation_id'])
              ->firstOrFail();

          $conversation->title = trim($data['title']);
          $conversation->save();

          $archived = ($data['return_archived'] ?? '') === '1' ? '1' : null;

          return redirect()->route('ellie.index', array_filter([
              'conversation_id' => $conversation->id,
              'archived' => $archived,
          ]));
      }

      public function archive(Request $request)
        {
            $user = Auth::user();

            $data = $request->validate([
                'conversation_id' => 'required|integer',
                'return_archived' => 'nullable|string',
            ]);

            $conversation = AiConversation::where('user_id', $user->id)
                ->where('id', $data['conversation_id'])
                ->firstOrFail();

            $conversation->status = 'archived';
            $conversation->save();

            $returnArchived = ($data['return_archived'] ?? '') === '1';

            if ($returnArchived) {
                // If user is viewing archived, stay in archived mode and pick next archived conversation
                $nextConversation = AiConversation::where('user_id', $user->id)
                    ->where('status', 'archived')
                    ->where('id', '!=', $conversation->id)
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id')
                    ->first();

                return redirect()->route('ellie.index', array_filter([
                    'archived' => '1',
                    'conversation_id' => $nextConversation?->id,
                ]));
            }

            // Default: move user to next active conversation (excluding the one just archived)
            $nextConversation = AiConversation::where('user_id', $user->id)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', 'active');
                })
                ->where('id', '!=', $conversation->id)
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();

            return redirect()->route('ellie.index', array_filter([
                'conversation_id' => $nextConversation?->id,
            ]));
        }

        public function unarchive(Request $request)
        {
            $user = Auth::user();

            $data = $request->validate([
                'conversation_id' => 'required|integer',
                'return_archived' => 'nullable|string',
            ]);

            $conversation = AiConversation::where('user_id', $user->id)
                ->where('id', $data['conversation_id'])
                ->firstOrFail();

            $conversation->status = 'active';
            $conversation->save();

            $returnArchived = ($data['return_archived'] ?? '') === '1';

            if ($returnArchived) {
                // User is viewing archived list: this conversation disappears, so go to next archived
                $nextConversation = AiConversation::where('user_id', $user->id)
                    ->where('status', 'archived')
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id')
                    ->first();

                return redirect()->route('ellie.index', array_filter([
                    'archived' => '1',
                    'conversation_id' => $nextConversation?->id,
                ]));
            }

            // Otherwise, keep user on the now-active conversation
            return redirect()->route('ellie.index', [
                'conversation_id' => $conversation->id,
            ]);
        }
}
