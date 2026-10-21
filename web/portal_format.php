<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

/**
 * Gedeelde weergave van bedragen. Zelfde regels als de contractkaarten.
 */

function portal_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_format_amount(float $amount): string
{
    return '€ ' . number_format($amount, 2, ',', '.');
}

function portal_format_quantity(float $quantity): string
{
    if (abs($quantity - round($quantity)) < 0.00001) {
        return number_format($quantity, 0, ',', '.');
    }

    return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
}

function portal_quantity_unit(string $typeLabel): string
{
    switch ($typeLabel) {
        case 'Uren':
            return ' uur';
        case 'Materiaal':
            return ' st';
        case 'Kilometers':
            return ' km';
        default:
            return '';
    }
}

function portal_format_quantity_with_unit(float $quantity, string $typeLabel): string
{
    return portal_format_quantity($quantity) . portal_quantity_unit($typeLabel);
}

function portal_format_percent(?float $percent): string
{
    if ($percent === null) {
        return '—';
    }

    return number_format($percent, 1, ',', '.') . '%';
}

function portal_display_value(string $value): string
{
    return $value !== '' ? $value : '—';
}

function portal_parse_date_param(string $value): string
{
    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return '';
    }

    $parts = explode('-', $value);
    if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
        return '';
    }

    return $value;
}

function portal_amount_class(float $amount, string $kind): string
{
    if (abs($amount) < 0.00001) {
        return 'amount-zero';
    }
    if ($kind === 'cost') {
        return 'amount-cost';
    }
    if ($kind === 'revenue') {
        // Negatieve opbrengsten (credietnota's) rood, niet groen
        return $amount < 0 ? 'amount-cost' : 'amount-revenue';
    }
    return $amount < 0 ? 'amount-cost' : 'amount-revenue';
}
