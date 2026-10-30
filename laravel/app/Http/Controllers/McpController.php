<?php

namespace App\Http\Controllers;

use App\Agent\AgentAccess;
use App\Agent\Tools;
use App\Models\AgentToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * #35 Stage 1: a small MCP server (Model Context Protocol, Streamable HTTP, JSON responses) at POST /mcp.
 * Sign-in: "Authorization: Bearer dsk_..." (Company Settings > AI agent). The agent acts as that token's user, sees only
 * the tools the user's permissions allow, and every tool call is logged. Read-only. OFF unless an owner turns it on.
 */
class McpController extends Controller
{
    public const PROTOCOL = '2025-06-18';

    public const PER_MINUTE = 60;

    public function __invoke(Request $request): JsonResponse
    {
        if (! AgentAccess::enabled()) {
            return response()->json(['error' => 'AI agent access is turned off for this company.'], 403);
        }
        $token = AgentAccess::find($request->bearerToken());
        if (! $token) {
            return response()->json(['error' => 'Missing, expired or revoked access token.'], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        $key = 'mcp:'.$token->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return response()->json(['error' => 'Too many requests. Wait a minute.'], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $msg = $request->json()->all();
        if (array_is_list($msg) && $msg) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Batches are not supported.']], 400);
        }
        $id = $msg['id'] ?? null;
        $method = (string) ($msg['method'] ?? '');
        if (($msg['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return $this->error($id, -32600, 'Invalid JSON-RPC request.');
        }
        if (! array_key_exists('id', $msg)) {
            return response()->json(null, 202); // notification (e.g. notifications/initialized): nothing to answer
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'distrosku', 'title' => \App\Models\Company::current()->name.' (DistroSKU)', 'version' => (string) config('erp.version')],
                'instructions' => 'Read-only access to this distributor\'s DistroSKU ERP as '.$token->user->name.'. Customers are found by account number; products by SKU, UPC or name. Nothing can be changed through these tools.',
            ]),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => Tools::forUser($token->user)]),
            'tools/call' => $this->call($id, $token, (array) ($msg['params'] ?? [])),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    private function call(mixed $id, AgentToken $token, array $params): JsonResponse
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (! Tools::allowed($token->user, $name)) {
            return $this->error($id, -32602, "Unknown tool or not allowed for this user: {$name}");
        }
        $t0 = microtime(true);
        $ok = true;
        $err = null;
        try {
            DB::beginTransaction(); // read-only by construction: whatever a tool touched is rolled back
            try {
                $data = app(Tools::class)->call($token->user, $name, $args);
            } finally {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            $ok = false;
            $err = $e instanceof \InvalidArgumentException || $e instanceof \App\Exceptions\WorkflowException ? $e->getMessage() : 'The tool failed.';
            if (! $e instanceof \InvalidArgumentException && ! $e instanceof \App\Exceptions\WorkflowException) {
                report($e);
            }
        }
        \App\Models\AgentAction::create(['agent_token_id' => $token->id, 'user_id' => $token->user_id, 'tool' => $name,
            'input_hash' => hash('sha256', json_encode($args)), 'input' => $args, 'ok' => $ok, 'error' => $err ? mb_substr($err, 0, 255) : null,
            'ms' => (int) round((microtime(true) - $t0) * 1000)]);

        return $this->result($id, $ok
            ? ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]], 'structuredContent' => ['result' => $data], 'isError' => false]
            : ['content' => [['type' => 'text', 'text' => $err]], 'isError' => true]);
    }

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200, ['MCP-Protocol-Version' => self::PROTOCOL]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], 200);
    }
}
