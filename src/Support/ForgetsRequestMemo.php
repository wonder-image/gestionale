<?php

namespace Wonder\Plugin\Gestionale\Support;

/**
 * Per i Model letti con RequestMemo: chi scrive dal Model butta la copia del
 * gruppo `MEMO_GROUP`. Le scritture dirette (backend, seeding) chiamano
 * RequestMemo::forget() da sé.
 */
trait ForgetsRequestMemo
{
    public static function create(array $values): object
    {
        $result = parent::create($values);
        RequestMemo::forget(self::MEMO_GROUP);

        return $result;
    }

    public static function update(array $values, int|string $id): object
    {
        $result = parent::update($values, $id);
        RequestMemo::forget(self::MEMO_GROUP);

        return $result;
    }

    public static function delete(int|string $id): object
    {
        $result = parent::delete($id);
        RequestMemo::forget(self::MEMO_GROUP);

        return $result;
    }
}
