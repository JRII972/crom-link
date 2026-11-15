<?php

defined('ABSPATH') || exit;

// Modèle commun : la bannière reste un bloc Couverture modifiable dans chaque page.
add_action('init', static function (): void {
    if (! function_exists('register_block_template')) {
        return;
    }

    register_block_template('crom-link//page-banner', [
        'title' => 'LBDR — Bannière pleine largeur',
        'description' => 'Bannière immersive et bandes alternées. Ajouter la composition « Bannière et bandes LBDR » dans une nouvelle page.',
        'post_types' => ['page'],
        'content' => file_get_contents(__DIR__.'/../templates/page-banner.html'),
    ]);

    register_block_pattern('crom-link/banner-bands', [
        'title' => 'Bannière et bandes LBDR',
        'description' => 'Choisir une image dans le bloc Couverture, puis modifier les textes et les bandes.',
        'categories' => ['featured'],
        'postTypes' => ['page'],
        'content' => file_get_contents(__DIR__.'/../patterns/banner-bands.html'),
    ]);
});
