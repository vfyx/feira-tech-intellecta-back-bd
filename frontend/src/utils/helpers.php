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
