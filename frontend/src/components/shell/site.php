<?php

declare(strict_types=1);

require_once __DIR__ . '/../../utils/helpers.php';
require_once __DIR__ . '/icon.php';

function intellecta_asset(string $file): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $base = preg_replace('#/public$#', '', $base);
    return ($base === '/' ? '' : $base) . '/assets/' . ltrim($file, '/');
}

function render_site(string $page, array $people, array $feed, array $notifications, array $messages, array $account): void
{
    $routes = require __DIR__ . '/../../config/routes.php';
    $publicPages = $routes['public'];
    $appPages = $routes['app'];
    $page = in_array($page, array_merge($publicPages, $appPages), true) ? $page : '404';

    render_page_head($page);

    // Páginas como Ajuda e Termos são públicas, mas para quem está logado abrem com o menu do site.
    $loggedIn = !empty($GLOBALS['clientState']['data']);
    if (in_array($page, $publicPages, true) && !($loggedIn && in_array($page, $appPages, true))) {
        include __DIR__ . '/../../' . $routes['views'][$page];
        render_page_footer();
        return;
    }

    if ($page === '404') {
        render_not_found();
        render_page_footer();
        return;
    }

    render_app_shell($page, $account);
    include __DIR__ . '/../../' . $routes['views'][$page];
    echo '</main>';
    render_rightbar($people, $feed, $account);
    echo '</div>';
    render_mobile_nav($page);
    render_page_footer();
}

function render_page_head(string $title): void
{
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $basePath = preg_replace('#/public$#', '', $basePath);
    $assetPath = ($basePath === '' || $basePath === '/') ? '/assets' : $basePath . '/assets';
    ?>
    <!doctype html>
    <html lang="pt-BR" data-theme="<?= !empty($GLOBALS['clientState']['data']['account']['settings']['light-theme']) ? 'light' : 'dark' ?>">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="theme-color" content="#0d110f">
      <?php $pageTitles = ['home' => 'Início', 'explore' => 'Explorar', 'notifications' => 'Notificações', 'chat' => 'Conversas', 'reclamacoes' => 'Reclamações', 'profile' => 'Perfil', 'saved' => 'Salvos', 'topics' => 'Tópicos', 'public-profile' => 'Perfil público', 'editar-perfil' => 'Editar perfil', 'sua-conta' => 'Sua conta', 'informacoes-conta' => 'Informações da conta', 'configuracao-senha' => 'Senha', 'alterar-senha' => 'Alterar senha', 'preferencias' => 'Preferências', 'privacidade' => 'Privacidade', 'seguranca' => 'Segurança', 'admin-institucional' => 'Administração', 'moderacao' => 'Moderação', 'ajuda' => 'Ajuda', 'termos' => 'Termos de uso', 'privacidade-legal' => 'Política de privacidade', 'login' => 'Entrar', 'register' => 'Criar conta', 'institutional-login' => 'Acesso institucional', '404' => 'Página não encontrada']; ?>
      <title><?= e($pageTitles[$title] ?? ucfirst($title)) ?> · Intellecta</title>
      <link rel="preconnect" href="https://fonts.googleapis.com">
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
      <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Lato:wght@400;700;900&display=swap" rel="stylesheet">
      <?php if ($title === 'reclamacoes'): ?><script src="<?= e($assetPath) ?>/js/complaints.js?v=<?= filemtime(__DIR__ . '/../../../assets/js/complaints.js') ?>" defer></script><?php endif; ?>
      <?php if ($title === 'admin-institucional'): ?><script src="<?= e($assetPath) ?>/js/admin.js?v=20261023" defer></script><link rel="stylesheet" href="<?= e($assetPath) ?>/css/admin.css?v=20261023"><?php endif; ?>
      <?php if ($title === 'moderacao'): ?><script src="<?= e($assetPath) ?>/js/moderation.js?v=20261022" defer></script><?php endif; ?>
      <link rel="stylesheet" href="<?= e($assetPath) ?>/css/styles.css?v=<?= filemtime(__DIR__ . '/../../../assets/css/styles.css') ?>">
      <script src="<?= e($assetPath) ?>/js/vendor/gsap.min.js?v=<?= filemtime(__DIR__ . '/../../../assets/js/vendor/gsap.min.js') ?>"></script>
      <script src="<?= e($assetPath) ?>/js/depth-carousel.js?v=<?= filemtime(__DIR__ . '/../../../assets/js/depth-carousel.js') ?>" defer></script>
      <script src="<?= e($assetPath) ?>/js/app.js?v=<?= filemtime(__DIR__ . '/../../../assets/js/app.js') ?>" defer></script>
    </head>
    <body>
    <?php
}

function render_logo(string $href = '?page=home', bool $compact = false): void
{
    ?>
    <a class="brand <?= $compact ? 'brand--compact' : '' ?>" href="<?= e($href) ?>" aria-label="Intellecta">
      <img class="brand-mark" src="<?= e(intellecta_asset('images/intellecta_icon_branco_vetor.svg')) ?>" alt="" aria-hidden="true">
      <?php if (!$compact): ?><span class="brand-name">INTELLECTA</span><?php endif; ?>
    </a>
    <?php
}

function render_page_footer(): void
{
    ?>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
    <script type="application/json" id="app-state"><?= json_encode($GLOBALS['clientState'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    </body>
    </html>
    <?php
}

function render_app_shell(string $page, array $account): void
{
    ?>
    <div class="app-shell">
      <?php render_sidebar($page, $account); ?>
      <main class="main-column">
    <?php
}

function render_sidebar(string $page, array $account): void
{
    $items = [
        'home' => ['home', 'Início'],
        'explore' => ['search', 'Explorar'],
        'notifications' => ['bell', 'Notificações'],
        'chat' => ['message', 'Conversas'],
        'profile' => ['user', 'Perfil'],
        'saved' => ['bookmark', 'Salvos'],
        'topics' => ['spark', 'Tópicos'],
    ];
    ?>
    <aside class="sidebar">
      <?php render_logo(); ?>
      <nav class="sidebar__nav" aria-label="Navegação principal">
        <span class="sidebar__nav-label">Navegar</span>
        <?php foreach ($items as $key => [$icon, $label]): ?>
          <a class="nav-item <?= $page === $key ? 'active' : '' ?>" href="?page=<?= e($key) ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>>
            <span class="nav-icon"><?= icon($icon) ?></span><?= e($label) ?>
            <?php if ($key === 'chat'): ?><span class="dm-unread" data-dm-unread hidden></span><?php endif; ?>
            <?php if ($key === 'notifications'): ?><span class="nav-item__count" data-unread-count>0</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
      <?php if (($account['role'] ?? '') === 'admin'): ?><a class="nav-item" href="?page=admin-institucional"><?= icon('settings') ?> Administração</a><?php endif; ?>
      <?php if (in_array($account['role'] ?? '', ['moderador','admin'], true)): ?><a class="nav-item" href="?page=moderacao"><?= icon('flag') ?> Moderação</a><?php endif; ?>
      <nav class="sidebar__nav sidebar__nav--secondary" aria-label="Recursos">
        <span class="sidebar__nav-label">Seu espaço</span>
        <a class="nav-item <?= in_array($page, ['preferencias', 'privacidade', 'seguranca'], true) ? 'active' : '' ?>" href="?page=preferencias" title="Preferências" aria-label="Preferências">
          <span class="nav-icon"><?= icon('settings') ?></span>Preferências
        </a>
        <a class="nav-item <?= in_array($page, ['ajuda', 'termos', 'privacidade-legal'], true) ? 'active' : '' ?>" href="?page=ajuda" title="Ajuda" aria-label="Ajuda">
          <span class="nav-icon"><?= icon('help') ?></span>Ajuda
        </a>
      </nav>
      <a class="sidebar-profile-link <?= in_array($page, ['profile', 'editar-perfil'], true) ? 'active' : '' ?>" href="?page=editar-perfil" aria-label="Abrir edição do perfil">
        <?php if (!empty($account['avatar'])): ?>
          <img class="avatar avatar-image" src="<?= e($account['avatar']) ?>" alt="">
        <?php else: ?>
          <div class="avatar"><?= e(initials($account['name'])) ?></div>
        <?php endif; ?>
        <span class="sidebar-profile-link__copy"><strong><?= e($account['name']) ?></strong><small>@<?= e($account['username']) ?></small></span>
        <span class="sidebar-profile-link__status" aria-label="Online"></span>
      </a>
    </aside>
    <?php
}

function render_mobile_nav(string $page): void
{
    $items = [
        'home' => ['home', 'Início'],
        'explore' => ['search', 'Explorar'],
        'notifications' => ['bell', 'Avisos'],
        'chat' => ['message', 'Conversas'],
        'profile' => ['user', 'Perfil'],
        'saved' => ['bookmark', 'Salvos'],
    ];
    ?>
    <nav class="mobile-nav" aria-label="Navegação móvel">
      <?php foreach ($items as $key => [$icon, $label]): ?>
        <a class="<?= $page === $key ? 'active' : '' ?>" href="?page=<?= e($key) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>>
          <span><?= icon($icon) ?></span><span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php
}

function render_rightbar(array $people = [], array $feed = [], array $account = []): void
{
    // Sugestões da coluna direita: pessoas para conhecer e tópicos mais usados.
    $suggested = array_slice(array_values(array_filter($people, fn($p) => !hidden_from_explore($p) && ($p['username'] ?? '') !== ($account['username'] ?? ''))), 0, 4);
    $trending = [];
    foreach ($feed as $post) { if (!empty($post['topic'])) $trending[$post['topic']] = ($trending[$post['topic']] ?? 0) + 1; }
    arsort($trending);
    $trending = array_slice($trending, 0, 4, true);
    ?>
    <aside class="rightbar">
      <label class="eyebrow" for="global-search">Pesquisa</label>
      <div class="search-field">
        <span><?= icon('search') ?></span>
        <input class="input" id="global-search" data-filter placeholder="Pesquisar no Intellecta">
      </div>
      <?php if ($suggested): ?>
      <section class="rightbar-block" aria-labelledby="rb-people">
        <h2 class="eyebrow" id="rb-people">Pessoas para conhecer</h2>
        <ul class="rightbar-people">
          <?php foreach ($suggested as $p): ?>
          <li><a href="?page=public-profile&amp;user=<?= e($p['username']) ?>"><?= avatar_html($p) ?><span><strong><?= e($p['name']) ?><?= founder_badge_html($p) ?></strong><small>@<?= e($p['username']) ?></small></span></a></li>
          <?php endforeach; ?>
        </ul>
        <a class="rightbar-more" href="?page=explore">Ver todas as pessoas →</a>
      </section>
      <?php endif; ?>
      <?php if ($trending): ?>
      <section class="rightbar-block" aria-labelledby="rb-topics">
        <h2 class="eyebrow" id="rb-topics">Tópicos em alta</h2>
        <ul class="rightbar-topics">
          <?php foreach ($trending as $topicName => $topicCount): ?>
          <li><a href="?page=home&amp;topic=<?= urlencode((string) $topicName) ?>"><strong>#<?= e((string) $topicName) ?></strong><small><?= e(plural((int) $topicCount, 'publicação', 'publicações')) ?></small></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>
      <button class="ai-launcher" type="button" data-ai-toggle aria-expanded="false" aria-controls="intellia-panel" aria-label="Abrir Intellia">
        <img src="<?= e(intellecta_asset('images/intellecta_icon_branco_vetor.svg')) ?>" alt="" aria-hidden="true">
        <span class="ai-launcher__pulse"></span>
      </button>
      <div class="ai-backdrop" data-ai-backdrop hidden></div>
      <section class="panel ai-card" id="intellia-panel" aria-labelledby="ai-title" aria-hidden="true" hidden>
        <div class="ai-card__head">
          <div class="ai-brand">
            <span class="ai-brand__mark">I</span>
            <div><strong id="ai-title">INTELLIA</strong><small>assistente de estudos</small></div>
          </div>
          <div class="ai-card__controls"><span class="ai-status">online</span><button class="ai-new-chat" type="button" data-ai-reset title="Começar uma nova conversa"><span aria-hidden="true">+</span> Nova conversa</button><button class="ai-logo-close" type="button" data-ai-toggle aria-label="Fechar Intellia"><img src="<?= e(intellecta_asset('images/intellecta_icon_branco_vetor.svg')) ?>" alt="" aria-hidden="true"></button></div>
        </div>
        <div class="ai-orbit ai-orbit--one"></div><div class="ai-orbit ai-orbit--two"></div><div class="ai-orbit ai-orbit--three"></div>
        <div class="ai-intro">
          <span class="ai-intro__spark"><?= icon('spark') ?></span>
          <div><strong>Como posso ajudar?</strong><p>Pergunte qualquer coisa. Quando houver material da escola sobre o assunto, eu mostro as fontes.</p></div>
        </div>
        <div class="ai-messages" data-ai-messages aria-live="polite">
          <div class="ai-message ai-message--bot"><p>Pergunte sobre os conteúdos da escola. Vou buscar trechos relacionados e mostrar as fontes encontradas.</p></div>
        </div>
        <div class="ai-suggestions" aria-label="Sugestões rápidas">
          <button type="button" data-ai-prompt="Monte um plano de estudos para a minha próxima prova. Pergunte o que precisar saber antes.">Plano de estudos</button>
          <button type="button" data-ai-prompt="Resuma para mim o assunto: " data-ai-fill>Resumir assunto</button>
          <button type="button" data-ai-prompt="Explique de forma mais simples a sua última resposta, com um exemplo.">Explicar melhor</button>
        </div>
        <form class="ai-form" data-ai-form>
          <label class="sr-only" for="ai-question">Pergunte à Intellecta IA</label>
          <textarea class="input" id="ai-question" rows="1" placeholder="Qual assunto você quer entender?" maxlength="10000" required></textarea>
          <button class="ai-send" type="submit" aria-label="Enviar pergunta"><?= icon('send') ?></button>
        </form>
      </section>
    </aside>
    <?php
}

function render_not_found(): void
{
    ?>
    <main class="auth-form-wrap not-found-page">
      <section class="auth-card card">
        <span class="eyebrow">404</span>
        <h2>Essa página se perdeu.</h2>
        <p>O endereço não existe ou ainda está sendo construído.</p>
        <a class="btn btn-primary not-found-page__link" href="?page=home">
          Voltar para o início
        </a>
      </section>
    </main>
    <?php
}
