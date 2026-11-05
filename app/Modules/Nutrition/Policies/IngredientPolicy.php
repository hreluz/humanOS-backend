<?php

namespace App\Modules\Nutrition\Policies;

use App\Models\User;
use App\Modules\Nutrition\Models\Ingredient;

/**
 * Who can change an ingredient (SPEC 18): only its owner. A catalog ingredient is visible to everyone but
 * changed by no one, so it's denied (403). Who can see an ingredient is decided by the route binding and the
 * search scope (`Ingredient::scopeVisibleTo`), so another user's ingredient is a 404 before this runs.
 */
class IngredientPolicy
{
    public function update(User $user, Ingredient $ingredient): bool
    {
        return $ingredient->isOwnedBy($user);
    }

    public function delete(User $user, Ingredient $ingredient): bool
    {
        return $ingredient->isOwnedBy($user);
    }
}
