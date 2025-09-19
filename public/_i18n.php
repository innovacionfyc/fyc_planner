<?php
// public/_i18n.php — Traducciones rápidas (UI)

function tr_board_role(string $r): string
{
    $m = ['owner' => 'propietario', 'editor' => 'editor', 'viewer' => 'lector'];
    return $m[$r] ?? $r;
}

function tr_team_role(string $r): string
{
    $m = ['owner' => 'propietario', 'member' => 'miembro'];
    return $m[$r] ?? $r;
}

function tr_priority_label(string $p, bool $upper = false): string
{
    $m = ['low' => 'baja', 'med' => 'media', 'high' => 'alta', 'urgent' => 'urgente'];
    $t = $m[$p] ?? $p;
    return $upper ? mb_strtoupper($t, 'UTF-8') : $t;
}
