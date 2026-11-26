<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string
{
    return strtoupper(substr(trim($name), 0, 1));
}

function avatar_html(array $person, string $classes = ''): string
{
    $class = trim('avatar ' . $classes);
    if (!empty($person['avatar'])) {
        return '<img class="' . e($class . ' avatar-image') . '" src="' . e($person['avatar']) . '" alt="Foto de perfil de ' . e($person['name'] ?? '') . '">';
    }
    return '<div class="' . e($class) . '">' . e(initials($person['name'] ?? '?')) . '</div>';
}


/** Contas usadas só para testar o sistema: não aparecem no Explorar nem nas sugestões. */
function hidden_from_explore(array $person): bool
{
    $username = strtolower((string) ($person['username'] ?? ''));
    return in_array($username, ['admin'], true) || preg_match('/^(teste|validacao)_/', $username) === 1;
}

function plural(int $count, string $one, string $many): string
{
    return $count . ' ' . ($count === 1 ? $one : $many);
}

function founder_badge_html(array $person): string
{
    $username = strtolower(ltrim(trim((string) ($person['username'] ?? '')), '@'));
    if (!in_array($username, ['miguel', 'bloriun', 'carvaalhooxz_', 'ana_vicente', 'arycano'], true)) return '';
    return '<span class="founder-badge" title="Fundador do Intellecta"><span aria-hidden="true">✦</span> Founder</span>';
}