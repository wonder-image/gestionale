<?php

namespace Wonder\Plugin\Gestionale\Support\SalesAgents;

use Throwable;
use Wonder\Backend\Support\WelcomeMail;
use Wonder\Plugin\Gestionale\Models\SalesAgents\SalesAgent;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * L'utente del commerciale creato dalla pagina «Nuovo commerciale» (#77):
 * nome, cognome ed email bastano. Nasce col ruolo `sales_agent` nell'area
 * backend e riceve la mail per scegliere la password.
 *
 * Se l'email è già di un utente col ruolo «Commerciale» senza scheda lo
 * riusa (es. un salvataggio rimandato indietro); con un altro ruolo si ferma.
 */
final class SalesAgentUsers
{
    public const AREA = 'backend';

    /**
     * @return int l'id dell'utente
     * @throws UserError
     */
    public static function create(string $name, string $surname, string $email): int
    {
        $name = trim($name);
        $surname = trim($surname);
        $email = mb_strtolower(trim($email));

        if ($name === '' || $email === '') {
            throw UserError::make('sales_agent.user_fields');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw UserError::make('sales_agent.user_email');
        }

        $existing = self::existing($email);

        if ($existing !== null) {
            return $existing;
        }

        return self::store($name, $surname, $email);
    }

    /** L'utente con questa email, se è un commerciale ancora senza scheda. */
    private static function existing(string $email): ?int
    {
        $user = infoUser($email, 'email');

        if (!is_object($user) || !($user->exists ?? false)) {
            return null;
        }

        $id = (int) ($user->id ?? 0);

        if (!array_key_exists($id, SalesAgents::userOptions())) {
            throw UserError::make('sales_agent.user_other_role');
        }

        if ((int) sqlCount(SalesAgent::$table, 'user_id = '.$id." AND deleted = 'false'") > 0) {
            throw UserError::make('sales_agent.user_taken');
        }

        return $id;
    }

    private static function store(string $name, string $surname, string $email): int
    {
        global $ALERT;

        $post = [
            'name' => $name,
            'surname' => $surname,
            'email' => $email,
            'authority' => SalesAgents::ROLE,
            'area' => self::AREA,
            'active' => 'true',
        ];

        $previous = $ALERT ?? null;
        $ALERT = null;

        try {
            $result = user($post);
        } catch (Throwable) {
            $result = null;
        }

        $alert = $ALERT;
        $ALERT = $previous;
        $id = is_object($result) && is_object($result->user ?? null) ? (int) ($result->user->id ?? 0) : 0;

        if (!empty($alert) || $id <= 0) {
            throw UserError::make('sales_agent.user_not_created');
        }

        // La mail non blocca: se non parte resta il recupero password.
        WelcomeMail::send($post + ['username' => (string) ($result->user->username ?? '')], $result->user);
        SalesAgents::forget();

        return $id;
    }
}
