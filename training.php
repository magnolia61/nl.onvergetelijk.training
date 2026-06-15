<?php

use CRM_Training_ExtensionUtil as E;

/**
 * Implements hook_civicrm_post().
 * 
 * PULL LOGICA:
 * Wanneer een Participant wordt opgeslagen voor een Trainingsdag, vullen we de
 * gegevens aan vanuit het hoofdkamp en sturen we de data door de leeftijd-calculator.
 */
function training_civicrm_post($op, $objectName, $objectId, &$objectRef) {

    if ($objectName !== 'Participant' || !in_array($op, ['create', 'edit'])) {
        return;
    }

    static $training_sync_running = [];
    if (isset($training_sync_running[$objectId])) {
        return;
    }

    $extdebug = 'training.post'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
    $apidebug   = FALSE;

    $training_post_start = microtime(TRUE);
    watchdog('civicrm_timing', base_microtimer("START training_post [PID: $objectId]"), NULL, WATCHDOG_DEBUG);

    try {
        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### TRAIN PULL [POST]: START SYNC NAAR GROEP PART [PID: $objectId]", "[TRAIN]");
        wachthond($extdebug, 2, "########################################################################");

        // ----------------------------------------------------------------------
        // 1. CONTEXT: Is dit een toerusting/training event?
        // ----------------------------------------------------------------------
        $params_context = [
            'checkPermissions'  => FALSE,
            'debug'             => $apidebug,
            'select'            => ['event_id.event_type_id', 'contact_id'],
            'where'             => [['id', '=', $objectId]],
        ];
        
        wachthond($extdebug, 7, 'params_context',           $params_context);
        $result_context = civicrm_api4('Participant', 'get',    $params_context)->first();
        wachthond($extdebug, 9, 'result_context',           $result_context);

        if (!$result_context) return;

        $eventtypes = get_event_types();
        $toer_ids   = $eventtypes['toer'] ?? [];

        if (in_array($result_context['event_id.event_type_id'] ?? 0, $toer_ids)) {

            $training_sync_running[$objectId] = TRUE;
            $contact_id = $result_context['contact_id'];

            // ----------------------------------------------------------------------
            // 2. DATA VERZAMELEN: Haal brongegevens op
            // ----------------------------------------------------------------------
            $allpart        = base_find_allpart($contact_id, date("Y-m-d H:i:s"));
            $pos_leid_id    = $allpart['result_allpart_pos_leid_part_id'] ?? NULL;

            if ($pos_leid_id && $pos_leid_id != $objectId) {

                // Data uit de hoofdkamp-inschrijving
                $part_array = base_pid2part($pos_leid_id);
                $event_data = base_eid2event($part_array['part_event_id'] ?? 0, $pos_leid_id);

                // --- LEEFTIJD BEREKENEN VIA PARTSTATUS ENGINE ---
                // We halen eerst de geboortedatum op uit het contactrecord
                $contact_data = civicrm_api4('Contact', 'get', [
                    'checkPermissions' => FALSE,
                    'select'           => ['birth_date'],
                    'where'            => [['id', '=', $contact_id]],
                ])->first();

                // Bouw een dummy part_array om de leeftijd-engine te voeden
                $age_input_array = [
                    'contact_id'       => $contact_id,
                    'birth_date'       => $contact_data['birth_date'] ?? NULL,
                    'id'               => $objectId, // Doel Participant ID
                    'event_start_date' => $part_array['event_start_date'] ?? NULL, // Focus op hoofdkamp datum
                ];

                // Reken de leeftijden uit. NULL groupID zodat hij NIET zelf gaat saven
                // (dat doen wij immers in de query hieronder).
                $age_calc = [];
                if (function_exists('partstatus_leeftijd_configure')) {
                    $age_calc = partstatus_leeftijd_configure($age_input_array, NULL, NULL, 'event');
                }

                // Map de berekende waarden veilig (Event of Nextkamp afhankelijk van prioriteit)
                // We geven prioriteit aan de leeftijd tijdens het *event*, met *nextkamp* als fallback.
                $source_age = ($age_calc['event']['leeftijd_decimalen'] ?? 0) > 0 
                              ? $age_calc['event'] 
                              : ($age_calc['next'] ?? []);

                $leeftijd_decimalen = $source_age['leeftijd_decimalen'] ?? NULL;
                $leeftijd_rondjaren = $source_age['leeftijd_rondjaren'] ?? NULL;


                // ==============================================================================
                // Bepaal de definitieve functies en rollen
                // ==============================================================================
                $sync_functie = $part_array['part_leid_functie'] ?? $part_array['part_functie'] ?? NULL;
                
                $sync_kamplang   = $part_array['part_kampnaam']         ?? NULL;
                $sync_kampkort   = $part_array['part_kampkort']         ?? NULL;
                $sync_kampsoort  = $part_array['part_kampsoort']        ?? NULL;
                $sync_kamplocatie= $event_data['eventkamp_pleklang']    ?? NULL;
                $sync_kampplaats = $event_data['eventkamp_stadlang']    ?? NULL;
                $sync_rol        = 'leiding'; // Default voor trainingsdag
                
                // OVERRIDE: Logica voor Bestuur en Kampstaf
                if ($sync_functie == 'bestuurslid') {
                    $sync_kampkort    = 'bst';
                    $sync_kamplang    = 'Bestuurstaken'; 
                    $sync_kamplocatie = 'N.v.t.';
                    $sync_kampplaats  = 'N.v.t.';
                    $sync_rol         = 'bestuur';
                } elseif ($sync_functie == 'kampstaf') {
                    $sync_kampkort    = 'kst';
                    $sync_kamplang    = 'Kampstaf taken'; 
                    $sync_kamplocatie = 'N.v.t.';
                    $sync_kampplaats  = 'N.v.t.';
                    $sync_rol         = 'kampstaf';
                }

                // ----------------------------------------------------------------------
                // 3. UPDATE: Schrijf enkel de PART-groep (118) velden naar de DB
                // ----------------------------------------------------------------------
                $data_sync_update = [
                    'PART.PART_naam'            => $part_array['displayname']           ?? NULL,
                    'PART.PART_kampkort'        => $sync_kampkort,
                    'PART.PART_kamplang'        => $sync_kamplang,
                    'PART.PART_kampsoort'       => $sync_kampsoort,
                    'PART.PART_kampstart'       => $part_array['part_kampstart']        ?? NULL,
                    'PART.PART_kampeinde'       => $part_array['part_kampeinde']        ?? NULL,
                    'PART.kamplocatie'          => $sync_kamplocatie,
                    'PART.kampplaats'           => $sync_kampplaats,
                    'PART.eventjaar'            => $part_array['event_start_date']      ?? NULL,
                    'PART.kampjaar'             => $part_array['part_kampjaar']         ?? NULL,
                    'PART.PART_kampweek_nr'     => $part_array['part_kampweek_nr']      ?? NULL,
                    'PART.PART_kamptype_naam'   => $part_array['part_kamptype_naam']    ?? NULL,
                    'PART.PART_kamptype_id'     => $part_array['part_kamptype_id']      ?? NULL,

                    // --- LEEFTIJD MAPPING ---
                    'PART.nextkamp_decimalen'   => $leeftijd_decimalen,
                    'PART.nextkamp_rondjaren'   => $leeftijd_rondjaren,

                    'PART.PART_kamprol'         => $sync_rol,
                    'PART.PART_kampfunctie'     => $sync_functie,

                    'PART.vakantieregio'        => $part_array['part_vakantieregio']    ?? NULL,
                    'PART.NAW_gecheckt'         => $part_array['part_nawgecheckt']      ?? NULL,
                    'PART.BIO_gecheckt'         => $part_array['part_biogecheckt']      ?? NULL,
                    'PART.PART_cid'             => $contact_id,
                    'PART.PART_pid'             => $part_array['part_id']               ?? NULL,
                    'PART.PART_eid'             => $part_array['part_event_id']         ?? NULL,
                ];
                $result_sync_update = base_api_wrapper('Participant', (int)$objectId, $data_sync_update, "TRAINING_SYNC", $extdebug);

                wachthond($extdebug, 1, "TRAIN_PULL_POST: Succesvolle sync naar PART groep voor PID $objectId.");
            }
        }
    } catch (\Exception $e) {
        wachthond(1, 1, "TRAIN_POST_ERROR", $e->getMessage());
    }

    $total_training_post_duur = number_format(microtime(TRUE) - $training_post_start, 3);
    watchdog('civicrm_timing', base_microtimer("EINDE training_post"), NULL, WATCHDOG_DEBUG);
}