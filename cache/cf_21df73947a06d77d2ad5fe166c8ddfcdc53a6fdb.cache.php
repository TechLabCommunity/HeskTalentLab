<?php if (!defined('IN_SCRIPT')) {die();} $hesk_settings['custom_fields']=array (
  'custom1' => 
  array (
    'use' => '1',
    'place' => '0',
    'type' => 'select',
    'req' => '2',
    'category' => 
    array (
    ),
    'name' => 'Area di interesse',
    'value' => 
    array (
      'show_select' => 1,
      'select_options' => 
      array (
        0 => 'Amministrazione e gestione',
        1 => 'CoWorking',
        2 => 'FabLab',
        3 => 'TechLab',
        4 => 'MusicLab',
        5 => 'VisualLab',
        6 => 'FoodLab',
        7 => 'Arena',
        8 => 'Sala riunioni',
      ),
    ),
    'order' => '10',
    'mfh_description' => 'Area alla quale fare la richiesta',
    'title' => 'Area di interesse',
    'name:' => 'Area di interesse:',
  ),
  'custom2' => 
  array (
    'use' => '1',
    'place' => '0',
    'type' => 'select',
    'req' => '2',
    'category' => 
    array (
    ),
    'name' => 'Specifica del ticket',
    'value' => 
    array (
      'show_select' => 1,
      'select_options' => 
      array (
        0 => 'Vorrei diventare socio',
        1 => 'Ho problemi di accesso alla struttura',
        2 => 'Vorrei commissionare a voi un lavoro ',
        3 => 'Voglio organizzare corsi ed eventi da voi',
        4 => 'Problema con un vostro evento/corso',
        5 => 'Pagamenti e rimborsi',
      ),
    ),
    'order' => '20',
    'mfh_description' => 'Perchè è stato aperto questo ticket',
    'title' => 'Specifica del ticket',
    'name:' => 'Specifica del ticket:',
  ),
);