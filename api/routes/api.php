<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversaController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/institutional-login', [AuthController::class, 'institutionalLogin']);

// O relato é anônimo e não exige autenticação.
Route::post('/reports', [ReportController::class, 'store']);

Route::get('/subjects', fn () =>
    \App\Models\Subject::orderBy('name')->get(['id', 'name'])
);

Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show']);

Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureActive::class])->group(function (): void {
    Route::post('/live/ticket', [\App\Http\Controllers\LiveController::class, 'ticket']);
    Route::post('/live/read', [\App\Http\Controllers\LiveController::class, 'read']);

    Route::get('/teacher-complaints', [\App\Http\Controllers\TeacherComplaintController::class, 'index']);
    Route::post('/teacher-complaints', [\App\Http\Controllers\TeacherComplaintController::class, 'store'])->middleware('throttle:20,1');
    Route::patch('/teacher-complaints/{id}', [\App\Http\Controllers\TeacherComplaintController::class, 'reply'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/intellecta/action', \App\Http\Controllers\IntellectaActionController::class)->middleware('throttle:120,1');
    Route::get('/my/posts', [PostController::class, 'mine']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/intellecta/state', \App\Http\Controllers\IntellectaStateController::class);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::post('/posts', [PostController::class, 'store']);
    Route::post('/posts/{post}/comments', [PostController::class, 'comment']);

    Route::post('/chat', ChatController::class)->middleware('throttle:10,1');

    // Mensagens diretas entre usuários.
    Route::prefix('dm')->group(function (): void {
        Route::get('/usuarios', [ConversaController::class, 'usuarios']);
        Route::get('/conversas', [ConversaController::class, 'index']);

        Route::post('/conversas', [ConversaController::class, 'store'])
            ->middleware('throttle:30,1');

        Route::get(
            '/conversas/{conversa}/mensagens',
            [ConversaController::class, 'mensagens']
        )->whereNumber('conversa');

        Route::post(
            '/conversas/{conversa}/mensagens',
            [ConversaController::class, 'enviar']
        )->whereNumber('conversa')->middleware('throttle:60,1');

        Route::patch(
            '/conversas/{conversa}/leitura',
            [ConversaController::class, 'ler']
        )->whereNumber('conversa');
    });

    Route::middleware('moderator')->group(function (): void {
        Route::get('/moderation', [\App\Http\Controllers\ModerationController::class, 'index']);
        Route::patch('/moderation/{type}/{id}', [\App\Http\Controllers\ModerationController::class, 'update'])->whereNumber('id');
        Route::patch('/posts/{post}/moderate', [PostController::class, 'moderate']);
        Route::get('/reports', [ReportController::class, 'index']);
        Route::patch('/reports/{report}', [ReportController::class, 'update']);
    });

    Route::middleware('admin')->group(function (): void {
        Route::get('/admin/{kind}', [\App\Http\Controllers\AdminController::class, 'index']);
        Route::patch('/admin/users/{user}', [\App\Http\Controllers\AdminController::class, 'user']);
        Route::patch('/admin/posts/{post}', [\App\Http\Controllers\AdminController::class, 'post']);
        Route::post('/admin/subjects', [\App\Http\Controllers\AdminController::class, 'subject']);
        Route::patch('/admin/subjects/{subject}', [\App\Http\Controllers\AdminController::class, 'subject']);
        Route::get('/institutional/users', [\App\Http\Controllers\InstitutionalController::class, 'index']);
        Route::patch('/institutional/users/{user}', [\App\Http\Controllers\InstitutionalController::class, 'update']);
    });

    Route::middleware('institutional')->get('/institutional/access', fn (Request $request) => [
        'user' => $request->user()->only(['id', 'name', 'email', 'username', 'role', 'verificado_em']),
    ]);
});
