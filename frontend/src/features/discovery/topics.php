<?php declare(strict_types=1); ?>

<header class="page-header">
  <div><span class="eyebrow">Curadoria da comunidade</span><h1>Tópicos</h1></div>
  <span class="page-count">para você</span>
</header>

<div class="page-content">
  <section class="topic-hero card">
    <span class="eyebrow">Aprenda em movimento</span>
    <h2>O que está despertando curiosidade?</h2>
    <p>Explore conversas por assunto e encontre pessoas com interesses próximos dos seus.</p>
  </section>
  <div class="topic-grid">
    <?php
      // Tópicos fixos + os que já aparecem nas publicações (ex.: #Ideias), dos mais usados para os menos usados.
      $topicIcons = ['Ideias' => 'spark', 'Tecnologia' => 'spark', 'Design' => 'image', 'Ciência' => 'spark', 'Matemática' => 'plus', 'Projetos' => 'edit', 'Carreira' => 'user'];
      foreach ($feed as $post) { if (!empty($post['topic']) && !isset($topicIcons[$post['topic']])) $topicIcons[$post['topic']] = 'spark'; }
      $topicCounts = [];
      foreach (array_keys($topicIcons) as $topicName) $topicCounts[$topicName] = count(array_filter($feed, fn($post) => ($post['topic'] ?? '') === $topicName));
      arsort($topicCounts);
    ?>
    <?php foreach (array_map(fn($n) => [$n, $topicCounts[$n], $topicIcons[$n]], array_keys($topicCounts)) as [$name, $count, $topicIcon]): ?>
      <a class="topic-card card" href="?page=home&amp;topic=<?= urlencode($name) ?>">
        <span class="topic-card__icon"><?= icon($topicIcon) ?></span>
        <strong>#<?= e($name) ?></strong>
        <span><?= e(plural($count, 'publicação', 'publicações')) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
