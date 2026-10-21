<?php
declare(strict_types=1);

// A sessão PHP guarda o token da API; ele nunca é enviado ao JavaScript.
function fail(string $message, int $status = 422): never
{
    throw new DomainException($message, $status);
}

function field(array $input, string $key, int $max = 500): string
{
    if (!is_string($input[$key] ?? null)) fail('Confira o campo ' . $key . '.');
    $value = trim($input[$key]);
    if ($value === '' || strlen($value) > $max) fail('Confira o campo ' . $key . '.');
    return $value;
}

function api_call(string $method, string $route, ?array $body = null, bool $authenticated = true): array
{
    $base = rtrim(getenv('INTELLECTA_API_URL') ?: 'http://127.0.0.1:8000/api', '/');
    $headers = ['Accept: application/json'];
    if ($authenticated) {
        if (empty($_SESSION['api_token'])) fail('Entre na sua conta para continuar.', 401);
        $headers[] = 'Authorization: Bearer ' . $_SESSION['api_token'];
    }
    $encoded = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
    if ($encoded !== null) $headers[] = 'Content-Type: application/json';

    if (function_exists('curl_init')) {
        $curl = curl_init($base . $route);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($encoded !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false) fail('Não foi possível conectar ao servidor. Tente novamente.', 503);
    } else {
        // Compatibilidade com o PHP do USBWebserver sem a extensão cURL.
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $encoded ?? '',
            'timeout' => 12,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $raw = @file_get_contents($base . $route, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int) $match[1];
        }
        if ($raw === false) fail('Não foi possível conectar ao servidor. Tente novamente.', 503);
    }

    try {
        $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        fail('O servidor retornou uma resposta inesperada.', 502);
    }
    if (!is_array($result)) fail('O servidor retornou uma resposta inesperada.', 502);
    if ($status >= 400 || $status === 0) {
        if ($status === 401 && $authenticated) {
            unset($_SESSION['api_token']);
            fail('Sua sessão expirou. Entre novamente.', 401);
        }
        if ($status >= 500 || $status === 0) {
            error_log('Intellecta: falha HTTP ' . $status . ' na rota ' . $route);
            fail('Não foi possível concluir no servidor. Tente novamente.', 503);
        }
        $message = 'Não foi possível concluir.';
        foreach ($result['errors'] ?? [] as $errors) {
            if (is_array($errors) && is_string($errors[0] ?? null)) { $message = $errors[0]; break; }
        }
        if ($message === 'Não foi possível concluir.' && is_string($result['message'] ?? null)) {
            $message = $result['message'];
        }
        fail($message, $status);
    }
    return $result;
}

function backend_state(): ?array
{
    if (empty($_SESSION['api_token'])) return null;
    try {
        return api_call('GET', '/intellecta/state');
    } catch (DomainException $exception) {
        if ($exception->getCode() === 401) return null;
        throw $exception;
    }
}

function api_action(string $action, array $input): array
{
    if ($action === 'login' || $action === 'register' || $action === 'institutional-login') {
        $body = [
            'email' => field($input, 'email', 255),
            // Senhas não são aparadas: espaços podem fazer parte delas.
            'password' => $input['password'] ?? null,
        ];
        if (!is_string($body['password']) || $body['password'] === '' || strlen($body['password']) > 200) {
            fail('Confira a senha.');
        }
        if ($action === 'register') {
            if (($input['terms'] ?? false) !== true) fail('Aceite os termos para continuar.');
            $body['name'] = field($input, 'name', 120);
            $body['username'] = field($input, 'username', 30);
            $body['password_confirmation'] = $input['password_confirmation'] ?? null;
        }
        $route = $action === 'institutional-login' ? '/auth/institutional-login' : '/auth/' . $action;
        $result = api_call('POST', $route, $body, false);
        if (!is_string($result['token'] ?? null) || !preg_match('/\A\d+\|[a-zA-Z0-9]+\z/', $result['token'])) {
            fail('O servidor não confirmou o acesso.', 502);
        }
        // Se o cadastro/login é refeito, tenta revogar a sessão anterior.
        if (!empty($_SESSION['api_token'])) {
            try { api_call('POST', '/auth/logout'); } catch (DomainException $exception) { /* Novo login já foi confirmado. */ }
        }
        session_regenerate_id(true);
        $_SESSION['api_token'] = $result['token'];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        return ['redirect' => '?page=home'];
    }
    if ($action === 'state') {
        return backend_state() ?? fail('Entre na sua conta para continuar.', 401);
    }
    if ($action === 'logout') {
        if (!empty($_SESSION['api_token'])) {
            try { api_call('POST', '/auth/logout'); }
            catch (DomainException $exception) {
                if ($exception->getCode() !== 401) throw $exception;
            }
        }
        $_SESSION = [];
        session_regenerate_id(true);
        return ['redirect' => '?page=login'];
    }
    if (in_array($action, ['profile','setting','post','like','save','comment','vote','repost','mute','delete-post','close-direct','open-direct','follow','block','notify','report','report-post','message','clear-chat','read','password'], true)) {
        if ($action === 'setting' && in_array($input['key'] ?? '', ['two-factor', 'email-updates'], true)) {
            fail('Este recurso ainda precisa de integração. A preferência não foi alterada.', 501);
        }
        return api_call('POST', '/intellecta/action', $input);
    }
    if ($action === 'complaints') return api_call('GET', '/teacher-complaints');
    if ($action === 'complaint-create') return api_call('POST', '/teacher-complaints', $input);
    if ($action === 'complaint-reply') {
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) fail('Reclamação inválida.');
        return api_call('PATCH', '/teacher-complaints/' . $id, ['reply' => $input['reply'] ?? '']);
    }
    if ($action === 'institutional-users') return api_call('GET', '/institutional/users');
    if ($action === 'institutional-user-update') {
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) fail('Usuário inválido.');
        return api_call('PATCH', '/institutional/users/' . $id, [
            'role' => $input['role'] ?? null, 'verified' => (bool) ($input['verified'] ?? false),
        ]);
    }
    fail('Este recurso ainda não está disponível nesta etapa.', 501);
}

function backend_start(): void
{
    date_default_timezone_set('America/Sao_Paulo');
    $dir = (getenv('INTELLECTA_STORAGE') ?: dirname(__DIR__, 2) . '/storage') . '/sessions-api';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Não foi possível iniciar a sessão.');
    session_name('INTELLECTA_API');
    session_save_path($dir);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'path' => '/',
    ]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    if (!isset($_GET['api'])) return;
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fail('Recarregue a página para continuar.', 403);
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 3200000) fail('Requisição muito grande.', 413);
        $raw = file_get_contents('php://input', false, null, 0, 3200001);
        if (strlen($raw) > 3200000) fail('Requisição muito grande.', 413);
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) fail('Requisição inválida.');
        echo json_encode(api_action(field($input, 'action', 40), $input), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (DomainException $exception) {
        http_response_code($exception->getCode() ?: 422);
        echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (JsonException $exception) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados inválidos.']);
    } catch (Throwable $exception) {
        error_log('Intellecta: erro interno ao atender requisição.');
        http_response_code(500);
        echo json_encode(['error' => 'Não foi possível concluir.']);
    }
    exit;
}
